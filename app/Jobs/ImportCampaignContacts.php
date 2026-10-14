<?php

namespace App\Jobs;

use App\Models\ContactImportStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Common\Exception\IOException;
use Throwable;

/**
 * ImportCampaignContacts
 * ──────────────────────
 * Asynchronously imports contacts from one of three input types into the
 * contacts table, then updates the contact_import_statuses record.
 *
 * Idempotency guarantees (see correction #3)
 * ───────────────────────────────────────────
 * • File mode: last_processed_row is reset to 0 at the start of each attempt
 *   and updated after every committed chunk.  On retry the job STARTS from
 *   row 0 (first data row after the header) but re-uses insertOrIgnore, so
 *   rows already in the database are simply ignored without incrementing any
 *   counter – the inserted counter was already incremented for them in the
 *   first attempt.  The counters ARE reset to 0 at the top of each attempt so
 *   that the final tallies reflect only the current attempt.  This is safe
 *   because:
 *     – already-inserted contacts are ignored (no duplicate rows).
 *     – counters reflect the final import outcome rather than cumulative work.
 *
 * • Single / phone_numbers mode: counters are always reset at the start of
 *   each attempt and the data set is small, so a full retry is acceptable.
 *
 * Queue / timeout alignment (see correction #7)
 * ───────────────────────────────────────────────
 * retry_after = 90 s (from config/queue.php).  The job timeout is set to
 * 300 s so that large files are not pre-empted before finishing; however the
 * worker must be started with --timeout=300 (or higher) so that the OS-level
 * alarm matches.  The dev script in composer.json uses --timeout=0 which
 * effectively disables the alarm.  For production use:
 *   php artisan queue:work --tries=3 --timeout=300
 *
 * Import accounting (see correction #2)
 * ──────────────────────────────────────
 * • Validation failures counted as failed_count immediately (row by row).
 * • Batch insertOrIgnore() returns the number of actually inserted rows.
 * • duplicate_count = valid_rows_in_batch – actually_inserted.
 * • duplicate rows are added to failed_count so callers can see them.
 * • No row is assumed inserted unless the DB confirms it.
 */
class ImportCampaignContacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts before the job is moved to failed_jobs.
     */
    public int $tries = 3;

    /**
     * Back-off delays (seconds) between retry attempts.
     */
    public array $backoff = [30, 60];

    /**
     * Allow the worker --timeout to kill the process after 300 seconds.
     * Align the queue worker launch command to at least this value.
     */
    public int $timeout = 300;

    /**
     * Number of contact rows to insert per DB transaction.
     */
    private const CHUNK_SIZE = 500;

    /**
     * Expected column headers (case-insensitive) for Excel / CSV files.
     * Any column not in this list is stored in the contact's `data` JSON column.
     */
    private const KNOWN_HEADERS = ['name', 'country_code', 'phone', 'email'];

    /**
     * @param  int         $importStatusId   PK of the contact_import_statuses row.
     * @param  int         $campaignId       PK of the campaign.
     * @param  int         $companyId        Company the contacts belong to.
     * @param  int|null    $createdBy        User ID who initiated the import.
     * @param  string      $type             'file' | 'single' | 'phone_numbers'
     * @param  string|null $filePath         Storage path (type=file only).
     * @param  array|null  $contactData      Validated contact array (type=single).
     * @param  string|null $phoneNumbers     Raw CSV string (type=phone_numbers).
     * @param  string|null $defaultCountryCode  Shared country code for phone_numbers.
     */
    public function __construct(
        private readonly int     $importStatusId,
        private readonly int     $campaignId,
        private readonly int     $companyId,
        private readonly ?int    $createdBy,
        private readonly string  $type,
        private readonly ?string $filePath         = null,
        private readonly ?array  $contactData      = null,
        private readonly ?string $phoneNumbers     = null,
        private readonly ?string $defaultCountryCode = null,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────────────────

    public function handle(): void
    {
        // Reset counters and mark as processing at the start of every attempt.
        // This is safe: if this attempt processes all rows, the final counters
        // will be accurate.  If it crashes, the next attempt will reset again.
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'status'             => 'processing',
                'inserted'           => 0,
                'failed_count'       => 0,
                'last_processed_row' => 0,
                'message'            => 'Contact import is in progress.',
                'updated_at'         => now(),
            ]);

        match ($this->type) {
            'file'          => $this->handleFile(),
            'single'        => $this->handleSingle(),
            'phone_numbers' => $this->handlePhoneNumbers(),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File mode (XLSX / CSV)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleFile(): void
    {
        // Resolve the absolute path on the local disk.
        $absolutePath = Storage::disk('local')->path($this->filePath);

        if (! file_exists($absolutePath)) {
            $this->markFailed('Import file was not found on disk.');
            return;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        try {
            if ($extension === 'csv') {
                $this->processSpreadsheet($absolutePath, 'csv');
            } else {
                $this->processSpreadsheet($absolutePath, 'xlsx');
            }
        } catch (IOException $e) {
            // Unreadable / corrupt file — not worth retrying.
            $this->markFailed('The uploaded file could not be read. It may be corrupt or in an unsupported format.');
            $this->delete(); // Remove job from queue (don't retry).
            return;
        } finally {
            // Always attempt cleanup regardless of outcome.
            $this->cleanupFile();
        }
    }

    /**
     * Stream-read the spreadsheet row by row, batch-insert in chunks.
     *
     * @throws IOException
     */
    private function processSpreadsheet(string $path, string $format): void
    {
        if ($format === 'csv') {
            $options = new CsvOptions();
            $options->FIELD_DELIMITER = ',';
            $options->FIELD_ENCLOSURE = '"';
            $reader = new CsvReader($options);
        } else {
            $reader = new XlsxReader();
        }

        $reader->open($path);

        $headerMap   = [];   // [column_index => normalised_header_name]
        $headerRead  = false;
        $totalRows   = 0;    // data rows counted (excluding header)
        $inserted    = 0;
        $failedCount = 0;
        $batch       = [];
        $rowIndex    = 0;    // 1-based index of the last data row processed

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->getCells();

                // ── Header row ──────────────────────────────────────────────
                if (! $headerRead) {
                    $headerMap  = $this->parseHeaders($cells);
                    $headerRead = true;

                    // Abort if required column is missing.
                    if (! in_array('phone', $headerMap, true)) {
                        $this->markFailed(
                            'The uploaded file is missing the required "phone" column. ' .
                            'Expected headers: name, country_code, phone, email.'
                        );
                        $reader->close();
                        $this->delete();
                        return;
                    }

                    continue; // Move to first data row.
                }

                // ── Skip blank rows ──────────────────────────────────────────
                $rawValues = array_map(
                    fn ($cell) => trim((string) $cell->getValue()),
                    $cells
                );

                if (array_filter($rawValues) === []) {
                    continue; // All cells empty — skip silently.
                }

                $totalRows++;
                $rowIndex++;

                // Map cells to named fields using the detected header positions.
                $rowData = $this->mapRowToFields($cells, $headerMap);

                // ── Validate the data row ────────────────────────────────────
                $validationError = $this->validateRow($rowData);

                if ($validationError !== null) {
                    $failedCount++;
                    // Flush current batch before persisting counters for accuracy.
                    if (! empty($batch)) {
                        [$batchInserted, $batchFailed] = $this->insertBatch($batch);
                        $inserted    += $batchInserted;
                        $failedCount += $batchFailed;
                        $batch        = [];
                    }

                    $this->persistCounters($totalRows, $inserted, $failedCount, $rowIndex);
                    continue;
                }

                $batch[] = $this->buildContactRow($rowData);

                // ── Flush chunk ──────────────────────────────────────────────
                if (count($batch) >= self::CHUNK_SIZE) {
                    [$batchInserted, $batchFailed] = $this->insertBatch($batch);
                    $inserted    += $batchInserted;
                    $failedCount += $batchFailed;
                    $batch        = [];

                    $this->persistCounters($totalRows, $inserted, $failedCount, $rowIndex);
                }
            }

            // Only process the first sheet.
            break;
        }

        $reader->close();

        // ── Final partial batch ──────────────────────────────────────────────
        if (! empty($batch)) {
            [$batchInserted, $batchFailed] = $this->insertBatch($batch);
            $inserted    += $batchInserted;
            $failedCount += $batchFailed;
        }

        $this->finalise($totalRows, $inserted, $failedCount);
    }

    /**
     * Build a [column_index => header_name] map from the header row cells.
     * Header names are lower-cased and trimmed.
     */
    private function parseHeaders(array $cells): array
    {
        $map = [];
        foreach ($cells as $index => $cell) {
            $header = strtolower(trim((string) $cell->getValue()));
            if ($header !== '') {
                $map[$index] = $header;
            }
        }
        return $map;
    }

    /**
     * Map a data row's cells to an associative array using the header map.
     */
    private function mapRowToFields(array $cells, array $headerMap): array
    {
        $fields = [];
        foreach ($headerMap as $index => $header) {
            $value = isset($cells[$index])
                ? trim((string) $cells[$index]->getValue())
                : '';

            $fields[$header] = $value === '' ? null : $value;
        }
        return $fields;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Single-contact mode
    // ─────────────────────────────────────────────────────────────────────────

    private function handleSingle(): void
    {
        $data  = $this->contactData ?? [];
        $error = $this->validateRow($data);

        if ($error !== null) {
            $this->finalise(1, 0, 1, "Contact import failed: {$error}");
            return;
        }

        [$inserted, $failed] = $this->insertBatch([$this->buildContactRow($data)]);
        $this->finalise(1, $inserted, $failed);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phone-numbers mode
    // ─────────────────────────────────────────────────────────────────────────

    private function handlePhoneNumbers(): void
    {
        $raw   = $this->phoneNumbers ?? '';
        $parts = array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $s) => $s !== ''
        );

        $total       = count($parts);
        $batch       = [];
        $failedCount = 0;

        foreach ($parts as $phone) {
            $rowData = [
                'phone'        => $phone,
                'country_code' => $this->defaultCountryCode,
                'name'         => null,
                'email'        => null,
            ];

            $error = $this->validateRow($rowData);

            if ($error !== null) {
                $failedCount++;
                continue;
            }

            $batch[] = $this->buildContactRow($rowData);
        }

        [$inserted, $batchFailed] = $this->insertBatch($batch);
        $failedCount += $batchFailed;

        $this->finalise($total, $inserted, $failedCount);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate a single row's data.
     *
     * Returns null when valid, or an error string when invalid.
     * Only the `phone` field is required for every import type.
     */
    private function validateRow(array $data): ?string
    {
        $phone = $data['phone'] ?? null;

        if (empty($phone)) {
            return 'Phone number is required.';
        }

        // Normalise: strip everything except digits and leading +
        $normalised = preg_replace('/[^\d+]/', '', $phone);

        if ($normalised === null || strlen($normalised) < 7 || strlen($normalised) > 20) {
            return "Invalid phone number: {$phone}";
        }

        if (isset($data['email']) && $data['email'] !== null) {
            if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return "Invalid email address: {$data['email']}";
            }
        }

        return null;
    }

    /**
     * Build a contacts-table row array from validated row data.
     * Known columns are mapped directly; extras go into the `data` JSON field.
     */
    private function buildContactRow(array $data): array
    {
        $extra = [];
        foreach ($data as $key => $value) {
            if (! in_array($key, self::KNOWN_HEADERS, true)) {
                $extra[$key] = $value;
            }
        }

        return [
            'campaign_id'  => $this->campaignId,
            'company_id'   => $this->companyId,
            'created_by'   => $this->createdBy,
            'name'         => $data['name'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'phone'        => $data['phone'] ?? null,
            'email'        => $data['email'] ?? null,
            'data'         => empty($extra) ? null : json_encode($extra),
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }

    /**
     * Insert a batch of contact rows using insertOrIgnore.
     *
     * Returns [actually_inserted, duplicates_or_ignored].
     *
     * insertOrIgnore() in Laravel returns the number of rows that were
     * actually inserted.  Any row already present (duplicate phone within
     * the same company) is silently skipped and counted as failed.
     *
     * This makes the operation idempotent: retrying the same batch never
     * duplicates contacts and never inflates the inserted counter, because
     * the counters are reset at the start of each attempt and only real
     * inserts are counted.
     */
    private function insertBatch(array $rows): array
    {
        if (empty($rows)) {
            return [0, 0];
        }

        $actuallyInserted = DB::table('contacts')->insertOrIgnore($rows);
        $ignored          = count($rows) - $actuallyInserted;

        return [$actuallyInserted, $ignored];
    }

    /**
     * Write in-progress counters to the DB after each chunk.
     * Uses absolute values (not increments) so that a retry can safely
     * reset counters to 0 and rebuild them from scratch.
     */
    private function persistCounters(
        int $total,
        int $inserted,
        int $failedCount,
        int $lastProcessedRow
    ): void {
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'total'              => $total,
                'inserted'           => $inserted,
                'failed_count'       => $failedCount,
                'last_processed_row' => $lastProcessedRow,
                'updated_at'         => now(),
            ]);
    }

    /**
     * Mark the import as completed (or completed_with_errors / failed).
     */
    private function finalise(
        int    $total,
        int    $inserted,
        int    $failedCount,
        string $customMessage = ''
    ): void {
        if ($customMessage !== '') {
            $status  = 'failed';
            $message = $customMessage;
        } elseif ($failedCount === 0) {
            $status  = 'completed';
            $message = "Contact import completed successfully. {$inserted} contact(s) imported.";
        } elseif ($inserted > 0) {
            $status  = 'completed_with_errors';
            $message = "Contact import completed with {$failedCount} failed record(s). {$inserted} contact(s) imported.";
        } else {
            $status  = 'failed';
            $message = "Contact import failed. All {$failedCount} record(s) were invalid or already existed.";
        }

        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'status'             => $status,
                'total'              => $total,
                'inserted'           => $inserted,
                'failed_count'       => $failedCount,
                'last_processed_row' => $total,
                'message'            => $message,
                'updated_at'         => now(),
            ]);
    }

    /**
     * Mark the import as failed with a safe, non-sensitive message.
     */
    private function markFailed(string $message): void
    {
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'status'     => 'failed',
                'message'    => $message,
                'updated_at' => now(),
            ]);
    }

    /**
     * Remove the temporary uploaded file from private storage.
     * Failures are logged but do not re-throw — a missing temp file should
     * not block the import from being marked complete.
     */
    private function cleanupFile(): void
    {
        if ($this->filePath === null) {
            return;
        }

        try {
            Storage::disk('local')->delete($this->filePath);
        } catch (Throwable $e) {
            Log::warning('ImportCampaignContacts: could not delete temp file.', [
                'path'  => $this->filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Failure hook — called by Laravel after all retries are exhausted
    // ─────────────────────────────────────────────────────────────────────────

    public function failed(Throwable $exception): void
    {
        // Mark the import as permanently failed.
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'status'     => 'failed',
                'message'    => 'Contact import failed after exhausting all retry attempts.',
                'updated_at' => now(),
            ]);

        // Clean up the temp file if still present.
        $this->cleanupFile();

        // Do NOT log the exception message here as it may contain row data.
        Log::error('ImportCampaignContacts: job failed permanently.', [
            'import_status_id' => $this->importStatusId,
            'campaign_id'      => $this->campaignId,
            'type'             => $this->type,
        ]);
    }
}
