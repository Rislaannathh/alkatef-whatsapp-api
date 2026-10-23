<?php

namespace App\Jobs;

use App\Models\ContactImportStatus;
use App\Services\ContactStorageService;
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
 * Supported input types
 * ─────────────────────
 * 'file'          – XLSX or CSV uploaded file (auto-detected by extension).
 * 'single'        – One contact object supplied directly in the request.
 * 'phone_numbers' – Comma-separated phone-number string.
 *
 * Import behaviours
 * ─────────────────
 * 'add_only'        – INSERT IGNORE.  Duplicates are counted as ignored.
 * 'update_existing' – Upsert via ON DUPLICATE KEY UPDATE.  Existing contacts
 *                     are updated; their non-empty fields are never overwritten
 *                     with blank values.
 *
 * Idempotency
 * ───────────
 * Counters are reset to 0 at the start of each attempt.  On retry the job
 * re-processes all rows; insertOrIgnore / upsert logic ensures idempotency.
 *
 * Queue alignment
 * ───────────────
 * Timeout = 300 s.  Start the worker with --timeout=300 (or higher).
 * Dev script in composer.json uses --timeout=0 (no alarm).
 *   php artisan queue:work --tries=3 --timeout=300
 */
class ImportCampaignContacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum retry attempts before the job is moved to failed_jobs. */
    public int $tries = 3;

    /** Back-off delays (seconds) between retry attempts. */
    public array $backoff = [30, 60];

    /** Allow the worker --timeout to kill the process after 300 seconds. */
    public int $timeout = 300;

    /** Number of contact rows to process per DB transaction. */
    private const CHUNK_SIZE = 500;

    /**
     * @param  int         $importStatusId     PK of the contact_import_statuses row.
     * @param  int         $campaignId         PK of the campaign.
     * @param  int         $companyId          Company the contacts belong to.
     * @param  int|null    $createdBy          User ID who initiated the import.
     * @param  string      $type               'file' | 'single' | 'phone_numbers'
     * @param  string|null $filePath           Storage path (type=file only).
     * @param  array|null  $contactData        Validated contact array (type=single).
     * @param  string|null $phoneNumbers       Raw CSV string (type=phone_numbers).
     * @param  string|null $defaultCountryCode Shared country code for phone_numbers.
     * @param  string      $importBehavior     'add_only' | 'update_existing'
     */
    public function __construct(
        public readonly int     $importStatusId,
        public readonly int     $campaignId,
        public readonly int     $companyId,
        public readonly ?int    $createdBy,
        public readonly string  $type,
        public readonly ?string $filePath           = null,
        public readonly ?array  $contactData        = null,
        public readonly ?string $phoneNumbers       = null,
        public readonly ?string $defaultCountryCode = null,
        public readonly string  $importBehavior     = 'add_only',
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────────────────

    public function handle(ContactStorageService $storage): void
    {
        // Reset counters and mark as processing at the start of every attempt.
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'status'             => 'processing',
                'inserted'           => 0,
                'updated'            => 0,
                'failed_count'       => 0,
                'last_processed_row' => 0,
                'message'            => 'Contact import is in progress.',
                'updated_at'         => now(),
            ]);

        match ($this->type) {
            'file'          => $this->handleFile($storage),
            'single'        => $this->handleSingle($storage),
            'phone_numbers' => $this->handlePhoneNumbers($storage),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File mode (XLSX / CSV)
    // ─────────────────────────────────────────────────────────────────────────

    private function handleFile(ContactStorageService $storage): void
    {
        $disk = Storage::disk('local');

        $absolutePath = $disk->path($this->filePath);

        Log::info('Campaign contact file lookup', [
            'campaign_id' => $this->campaignId,
            'file_path' => $this->filePath,
            'disk_root' => config('filesystems.disks.local.root'),
            'absolute_path' => $absolutePath,
            'storage_exists' => $disk->exists($this->filePath),
            'file_exists' => file_exists($absolutePath),
        ]);

        if (! $disk->exists($this->filePath) || ! file_exists($absolutePath)) {
            $this->markFailed('Import file was not found on disk.');
            return;
        }
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        // try {
        //     $this->processSpreadsheet($absolutePath, $extension === 'csv' ? 'csv' : 'xlsx', $storage);
        // } catch (IOException $e) {
        //     $this->markFailed('The uploaded file could not be read. It may be corrupt or in an unsupported format.');
        //     $this->delete(); // Do not retry — the file is unreadable.
        //     return;
        // } finally {
        //     $this->cleanupFile();
        // }
try {
    $this->processSpreadsheet(
        $absolutePath,
        $extension === 'csv' ? 'csv' : 'xlsx',
        $storage
    );

    // Delete the file only after successful processing.
    $this->cleanupFile();

} catch (IOException $e) {
    $this->markFailed(
        'The uploaded file could not be read. It may be corrupt or in an unsupported format.'
    );

    // This is treated as a permanent file-reading failure.
    $this->cleanupFile();
    $this->delete();

    return;

} catch (Throwable $e) {
    Log::error('ImportCampaignContacts: spreadsheet processing failed.', [
        'campaign_id' => $this->campaignId,
        'file_path' => $this->filePath,
        'error' => $e->getMessage(),
    ]);

    // Preserve the file so Laravel can retry the job.
    throw $e;
}

        
    }

    /**
     * Stream-read the spreadsheet row by row, batch-insert in chunks.
     *
     * @throws IOException
     */
    private function processSpreadsheet(
        string $path,
        string $format,
        ContactStorageService $storage
    ): void {
        if ($format === 'csv') {
            $options                  = new CsvOptions();
            $options->FIELD_DELIMITER = ',';
            $options->FIELD_ENCLOSURE = '"';
            $reader                   = new CsvReader($options);
        } else {
            $reader = new XlsxReader();
        }

        $reader->open($path);

        $headerMap   = [];   // [column_index => normalised_header_name]
        $headerRead  = false;
        $totalRows   = 0;    // data rows (excluding header)
        $inserted    = 0;
        $updated     = 0;
        $failedCount = 0;
        $batch       = [];
        $rowIndex    = 0;    // 1-based data-row counter

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->cells;

                // ── Header row ───────────────────────────────────────────────
                if (! $headerRead) {
                    $headerMap  = $this->parseHeaders($cells, $storage);
                    $headerRead = true;

                    if (! in_array('phone', $headerMap, true)) {
                        $this->markFailed(
                            'The uploaded file is missing the required "phone" column (accepted aliases: ' .
                                'phone, phone_number, mobile, mobile_number, cell, telephone, tel).'
                        );
                        $reader->close();
                        $this->delete(); // Not retryable — wrong file structure.
                        return;
                    }

                    continue;
                }

                // ── Skip completely blank rows ───────────────────────────────
                $rawValues = array_map(
                    fn($cell) => trim((string) $cell->getValue()),
                    $cells
                );

                if (array_filter($rawValues) === []) {
                    continue;
                }

                $totalRows++;
                $rowIndex++;

                $rowData         = $this->mapRowToFields($cells, $headerMap);
                $validationError = $storage->validateRow($rowData);

                if ($validationError !== null) {
                    $failedCount++;
                    if (! empty($batch)) {
                        [$bi, $bu, $bf] = $storage->storeBatch($batch, $this->importBehavior);
                        $inserted    += $bi;
                        $updated     += $bu;
                        $failedCount += $bf;
                        $batch        = [];
                    }
                    $this->persistCounters($totalRows, $inserted, $updated, $failedCount, $rowIndex);
                    continue;
                }

                $batch[] = $storage->buildContactRow(
                    $rowData,
                    $this->campaignId,
                    $this->companyId,
                    $this->createdBy
                );

                // ── Flush chunk ──────────────────────────────────────────────
                if (count($batch) >= self::CHUNK_SIZE) {
                    [$bi, $bu, $bf] = $storage->storeBatch($batch, $this->importBehavior);
                    $inserted    += $bi;
                    $updated     += $bu;
                    $failedCount += $bf;
                    $batch        = [];

                    $this->persistCounters($totalRows, $inserted, $updated, $failedCount, $rowIndex);
                }
            }

            break; // Only the first sheet.
        }

        $reader->close();

        // ── Final partial batch ──────────────────────────────────────────────
        if (! empty($batch)) {
            [$bi, $bu, $bf] = $storage->storeBatch($batch, $this->importBehavior);
            $inserted    += $bi;
            $updated     += $bu;
            $failedCount += $bf;
        }

        $this->finalise($totalRows, $inserted, $updated, $failedCount);
    }

    /**
     * Build a [column_index => normalised_header_name] map from the header row.
     * Header names are lower-cased, trimmed, and passed through the alias resolver.
     */
    private function parseHeaders(array $cells, ContactStorageService $storage): array
    {
        $map = [];
        foreach ($cells as $index => $cell) {
            $raw = trim((string) $cell->getValue());
            if ($raw !== '') {
                $map[$index] = $storage->normaliseHeader($raw);
            }
        }
        return $map;
    }

    /**
     * Map a data row's cells to an associative array using the resolved header map.
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

    private function handleSingle(ContactStorageService $storage): void
    {
        $data  = $this->contactData ?? [];
        $error = $storage->validateRow($data);

        if ($error !== null) {
            $this->finalise(1, 0, 0, 1, "Contact import failed: {$error}");
            return;
        }

        $row              = $storage->buildContactRow($data, $this->campaignId, $this->companyId, $this->createdBy);
        [$ins, $upd, $ign] = $storage->storeBatch([$row], $this->importBehavior);
        $this->finalise(1, $ins, $upd, $ign);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phone-numbers mode
    // ─────────────────────────────────────────────────────────────────────────

    private function handlePhoneNumbers(ContactStorageService $storage): void
    {
        $raw   = $this->phoneNumbers ?? '';
        $parts = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn(string $s) => $s !== ''
        ));

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

            $error = $storage->validateRow($rowData);

            if ($error !== null) {
                $failedCount++;
                continue;
            }

            $batch[] = $storage->buildContactRow($rowData, $this->campaignId, $this->companyId, $this->createdBy);
        }

        [$inserted, $updated, $batchFailed] = $storage->storeBatch($batch, $this->importBehavior);
        $failedCount += $batchFailed;

        $this->finalise($total, $inserted, $updated, $failedCount);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Status helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write in-progress counters to the DB after each chunk.
     */
    private function persistCounters(
        int $total,
        int $inserted,
        int $updated,
        int $failedCount,
        int $lastProcessedRow
    ): void {
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'total'              => $total,
                'inserted'           => $inserted,
                'updated'            => $updated,
                'failed_count'       => $failedCount,
                'last_processed_row' => $lastProcessedRow,
                'updated_at'         => now(),
            ]);
    }

    /**
     * Mark the import as completed / completed_with_errors / failed.
     */
    private function finalise(
        int    $total,
        int    $inserted,
        int    $updated,
        int    $failedCount,
        string $customMessage = ''
    ): void {
        if ($customMessage !== '') {
            $status  = 'failed';
            $message = $customMessage;
        } elseif ($failedCount === 0) {
            $status  = 'completed';
            $parts   = [];
            if ($inserted > 0) {
                $parts[] = "{$inserted} contact(s) imported";
            }
            if ($updated > 0) {
                $parts[] = "{$updated} contact(s) updated";
            }
            $summary = $parts ? implode(', ', $parts) . '.' : 'No new contacts were added.';
            $message = "Contact import completed successfully. {$summary}";
        } elseif ($inserted > 0 || $updated > 0) {
            $status  = 'completed_with_errors';
            $message = "Contact import completed with {$failedCount} skipped/failed record(s). "
                . "{$inserted} inserted, {$updated} updated.";
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
                'updated'            => $updated,
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
        DB::table('contact_import_statuses')
            ->where('id', $this->importStatusId)
            ->update([
                'status'     => 'failed',
                'message'    => 'Contact import failed after exhausting all retry attempts.',
                'updated_at' => now(),
            ]);

        $this->cleanupFile();

        Log::error('ImportCampaignContacts: job failed permanently.', [
            'import_status_id' => $this->importStatusId,
            'campaign_id'      => $this->campaignId,
            'type'             => $this->type,
            'import_behavior'  => $this->importBehavior,
        ]);
    }
}
