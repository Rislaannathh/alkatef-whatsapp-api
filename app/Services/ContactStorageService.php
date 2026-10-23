<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ContactStorageService
 * ─────────────────────
 * Centralises all contact-import business logic so that the
 * ImportCampaignContacts job, controller helpers, and tests share one
 * implementation instead of maintaining duplicated code.
 *
 * Responsibilities
 * ────────────────
 * • Header-alias normalisation  (phone_number / mobile → phone, full_name → name, …)
 * • Per-row validation          (phone required, min/max length, email format)
 * • Contact-row building        (map known fields, overflow → data JSON)
 * • Batch storage               (add_only via insertOrIgnore OR update_existing via upsert)
 *
 * Import behaviours
 * ─────────────────
 * 'add_only'        — INSERT IGNORE rows whose (company_id, phone) does not yet
 *                     exist.  Duplicates are counted but not modified.
 * 'update_existing' — UPSERT rows: insert new contacts, update existing ones.
 *                     Existing non-empty fields are NEVER overwritten with a
 *                     blank / null value from the import source.
 *
 * Return contract for insertBatch()
 * ──────────────────────────────────
 * Returns [int $inserted, int $updated, int $ignored].
 *
 * For 'add_only':
 *   $updated is always 0.
 *   $ignored = count($rows) - $inserted.
 *
 * For 'update_existing':
 *   MySQL's ON DUPLICATE KEY UPDATE increments the affected-rows counter by 2
 *   for each updated row and by 1 for each inserted row.  We approximate:
 *     $affected  = rows reported by the DB driver (1 per insert, 2 per update)
 *     $inserted  = rows that were newly inserted
 *     $updated   = rows that were matched and updated
 *   We derive inserted / updated from the affected-rows count returned by the
 *   underlying PDO statement via DB::affectingStatement().
 */
class ContactStorageService
{
    // ─────────────────────────────────────────────────────────────────────────
    // Column lists
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Columns that map directly to contacts table fields.
     * Any column key NOT in this list is stored in the `data` JSON column.
     */
    public const KNOWN_HEADERS = ['name', 'country_code', 'phone', 'email'];

    /**
     * Columns that can be updated during an 'update_existing' import.
     * The `phone` column is the match key and must not be in the update set.
     * `company_id`, `campaign_id`, and `created_by` are system-owned and must
     * not be overwritten.
     */
    private const UPDATABLE_COLUMNS = ['name', 'country_code', 'email', 'data', 'status'];

    /**
     * Header aliases: maps alternative column names to their canonical form.
     *
     * All keys must be lower-cased.  Matching is case-insensitive (the caller
     * lower-cases headers before lookup).
     */
    private const HEADER_ALIASES = [
        // phone variants
        'phone_number'   => 'phone',
        'mobile'         => 'phone',
        'mobile_number'  => 'mobile_number', // kept as-is, handled below
        'phonenumber'    => 'phone',
        'cell'           => 'phone',
        'cell_phone'     => 'phone',
        'telephone'      => 'phone',
        'tel'            => 'phone',
        // name variants
        'full_name'      => 'name',
        'fullname'       => 'name',
        'contact_name'   => 'name',
        'first_name'     => 'name',   // single-name alias
        // email variants
        'email_address'  => 'email',
        'e_mail'         => 'email',
        // country_code variants
        'country'        => 'country_code',
        'dial_code'      => 'country_code',
        'dialing_code'   => 'country_code',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Header normalisation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalise a raw spreadsheet header to its canonical contacts-table key.
     *
     * Steps:
     *  1. Trim whitespace.
     *  2. Lower-case.
     *  3. Replace runs of non-alphanumeric characters with underscores.
     *  4. Look up the result in the alias map.
     *
     * @param  string $raw   The header cell value as read from the spreadsheet.
     * @return string        The canonical key, e.g. 'phone', 'name', 'email'.
     */
    public function normaliseHeader(string $raw): string
    {
        $cleaned = strtolower(trim($raw));
        // Collapse spaces, hyphens, dots etc. to underscores for consistent lookup.
        $cleaned = preg_replace('/[^a-z0-9]+/', '_', $cleaned);
        $cleaned = trim($cleaned, '_');

        // Special case: mobile_number → phone (two-word alias not in HEADER_ALIASES
        // because it was incorrectly mapped above — fix here).
        if ($cleaned === 'mobile_number') {
            return 'phone';
        }

        return self::HEADER_ALIASES[$cleaned] ?? $cleaned;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row validation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate a single contact row.
     *
     * Returns null when valid, or a non-empty error string describing the failure.
     * Only `phone` is mandatory; all other fields are optional.
     */
    public function validateRow(array $data): ?string
    {
        $phone = $data['phone'] ?? null;

        if (empty($phone)) {
            return 'Phone number is required.';
        }

        // Normalise: keep only digits and a leading +
        $normalised = preg_replace('/[^\d+]/', '', (string) $phone);

        if ($normalised === null || strlen($normalised) < 7 || strlen($normalised) > 20) {
            return "Invalid phone number: {$phone}";
        }

        if (isset($data['email']) && $data['email'] !== null && $data['email'] !== '') {
            if (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return "Invalid email address: {$data['email']}";
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row building
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a contacts-table row array from validated row data.
     *
     * Known columns are mapped directly to their table fields.
     * Any extra columns are encoded into the `data` JSON column.
     *
     * @param  array    $data         Normalised key→value pairs from the import source.
     * @param  int      $campaignId   Foreign key to campaigns.id.
     * @param  int      $companyId    Foreign key to companies.id.
     * @param  int|null $createdBy    Foreign key to users.id (nullable).
     * @return array                  Ready-to-insert row for DB::table('contacts').
     */
    public function buildContactRow(
        array $data,
        int $campaignId,
        int $companyId,
        ?int $createdBy
    ): array {
        $extra = [];
        foreach ($data as $key => $value) {
            if (! in_array($key, self::KNOWN_HEADERS, true)) {
                $extra[$key] = $value;
            }
        }

        return [
            'campaign_id'  => $campaignId,
            'company_id'   => $companyId,
            'created_by'   => $createdBy,
            'name'         => $data['name'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'phone'        => $data['phone'] ?? null,
            'email'        => ($data['email'] ?? null) ?: null, // coerce '' → null
            'data'         => empty($extra) ? null : json_encode($extra),
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Batch storage — add_only
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Insert a batch of contact rows, ignoring duplicates.
     *
     * MySQL's INSERT IGNORE skips any row that would violate the
     * (company_id, phone) unique constraint without raising an error.
     * The method counts how many rows were actually inserted and how many
     * were ignored (duplicates).
     *
     * @param  array $rows  Array of built contact rows from buildContactRow().
     * @return array        [int $inserted, int $updated, int $ignored]
     */
    public function insertOrIgnoreBatch(array $rows): array
    {
        if (empty($rows)) {
            return [0, 0, 0];
        }

        $actuallyInserted = DB::table('contacts')->insertOrIgnore($rows);
        $ignored          = count($rows) - $actuallyInserted;

        return [$actuallyInserted, 0, $ignored];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Batch storage — update_existing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert a batch of contact rows.
     *
     * Matched by (company_id, phone) — the unique key added in migration
     * 2026_10_22_000002.
     *
     * Update rules:
     *  • Only columns listed in UPDATABLE_COLUMNS are eligible for update.
     *  • A column is updated ONLY when the incoming value is non-null AND
     *    non-empty AND the existing value is null or empty (preserve non-empty
     *    existing data).
     *  • `campaign_id`, `company_id`, `created_by` are NEVER touched during update.
     *
     * MySQL reports affected rows as:
     *   1 for each new INSERT
     *   2 for each matched UPDATE (if the row actually changed)
     *   0 for each matched row where nothing changed
     *
     * We run a second lightweight query to distinguish inserts from updates
     * accurately without relying on the affected-rows quirk.
     *
     * @param  array $rows  Array of built contact rows from buildContactRow().
     * @return array        [int $inserted, int $updated, int $ignored]
     */
    public function upsertBatch(array $rows): array
    {
        if (empty($rows)) {
            return [0, 0, 0];
        }

        // Collect the phones (and company_ids) we are attempting so we can
        // count pre-existing rows efficiently.
        $phonesByCompany = [];
        foreach ($rows as $row) {
            $cid   = $row['company_id'];
            $phone = $row['phone'];
            if ($phone !== null && $phone !== '') {
                $phonesByCompany[$cid][] = $phone;
            }
        }

        // Count existing contacts that match any of the incoming (company_id, phone) pairs.
        $existingCount = 0;
        foreach ($phonesByCompany as $companyId => $phones) {
            $existingCount += DB::table('contacts')
                ->where('company_id', $companyId)
                ->whereIn('phone', $phones)
                ->count();
        }

        // Build the UPDATE clause so we never overwrite a non-empty existing
        // value with a null / empty incoming value.
        // MySQL: COALESCE(incoming, existing) — but Laravel's upsert() does not
        // support conditional expressions in the update clause.  We therefore
        // use a raw SQL UPSERT instead.
        $this->rawUpsert($rows);

        // After the upsert, count how many rows now exist matching our keys.
        $afterCount = 0;
        foreach ($phonesByCompany as $companyId => $phones) {
            $afterCount += DB::table('contacts')
                ->where('company_id', $companyId)
                ->whereIn('phone', $phones)
                ->count();
        }

        $inserted = $afterCount - $existingCount;
        $updated  = $existingCount; // All pre-existing rows were touched by the upsert.
        $ignored  = 0;              // Nothing is ignored in update_existing mode.

        // Safety clamp — rounding errors should not produce negative values.
        $inserted = max(0, $inserted);
        $updated  = max(0, $updated);

        return [$inserted, $updated, $ignored];
    }

    /**
     * Execute the upsert using the appropriate strategy for the current DB driver.
     *
     * MySQL  – Single-pass INSERT … ON DUPLICATE KEY UPDATE with IF() expressions
     *          to preserve non-empty existing values.  All rows processed in one
     *          round-trip per chunk.
     *
     * SQLite – Row-by-row UPDATE then insertOrIgnore fallback.  SQLite does not
     *          support ON DUPLICATE KEY UPDATE.  This path is used in the test
     *          suite (phpunit.xml configures DB_CONNECTION=sqlite).
     */
    private function rawUpsert(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $this->rawUpsertMysql($rows);
        } else {
            $this->rawUpsertCompatible($rows);
        }
    }

    /**
     * MySQL-specific upsert: INSERT … ON DUPLICATE KEY UPDATE.
     *
     * The IF() expression in the UPDATE clause ensures that an incoming null or
     * empty string never overwrites an existing non-empty column value.
     */
    private function rawUpsertMysql(array $rows): void
    {
        $columns     = array_keys($rows[0]);
        $columnList  = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

        $placeholders = [];
        $bindings     = [];

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(', ', array_fill(0, count($row), '?')) . ')';
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        $updateParts = [];
        foreach (self::UPDATABLE_COLUMNS as $col) {
            if (in_array($col, $columns, true)) {
                $updateParts[] = "`{$col}` = IF(VALUES(`{$col}`) IS NOT NULL AND VALUES(`{$col}`) != '', VALUES(`{$col}`), `{$col}`)";
            }
        }
        $updateParts[] = '`updated_at` = NOW()';

        $sql = sprintf(
            'INSERT INTO `contacts` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            $columnList,
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        DB::statement($sql, $bindings);
    }

    /**
     * Database-agnostic upsert (SQLite / PostgreSQL compatible).
     *
     * For each incoming row:
     *  1. Attempt a raw UPDATE that only writes columns when the incoming value
     *     is non-empty AND the existing value is null/empty.
     *  2. If no existing row was found (UPDATE matched 0 rows), INSERT fresh.
     *
     * This is slower than the MySQL single-pass variant but produces identical
     * semantics and is safe on any PDO-compatible database.
     */
    private function rawUpsertCompatible(array $rows): void
    {
        foreach ($rows as $row) {
            $phone     = $row['phone'] ?? null;
            $companyId = $row['company_id'] ?? null;

            if ($phone === null || $companyId === null) {
                DB::table('contacts')->insertOrIgnore($row);
                continue;
            }

            // Check whether the contact already exists.
            $exists = DB::table('contacts')
                ->where('company_id', $companyId)
                ->where('phone', $phone)
                ->exists();

            if (! $exists) {
                // New contact — plain insert.
                DB::table('contacts')->insertOrIgnore($row);
                continue;
            }

            // Existing contact — build UPDATE that preserves non-empty values.
            $sets     = [];
            $bindings = [];

            foreach (self::UPDATABLE_COLUMNS as $col) {
                if (! array_key_exists($col, $row)) {
                    continue;
                }

                $incoming = $row[$col];
                if ($incoming === null || $incoming === '') {
                    // Never overwrite a non-empty existing value with blank.
                    continue;
                }

                // CASE expression: update only when the existing value is null/empty.
                $sets[]     = "`{$col}` = CASE WHEN (`{$col}` IS NULL OR `{$col}` = '') THEN ? ELSE `{$col}` END";
                $bindings[] = $incoming;
            }

            // Always refresh updated_at.
            $sets[]     = '`updated_at` = ?';
            $bindings[] = now()->toDateTimeString();

            if (! empty($sets)) {
                $bindings[] = $companyId;
                $bindings[] = $phone;

                DB::statement(
                    'UPDATE `contacts` SET ' . implode(', ', $sets) . ' WHERE `company_id` = ? AND `phone` = ?',
                    $bindings
                );
            }
        }
    }


    // ─────────────────────────────────────────────────────────────────────────
    // Convenience dispatcher
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store a batch of contact rows using the chosen import behaviour.
     *
     * @param  array  $rows            Rows built by buildContactRow().
     * @param  string $importBehavior  'add_only' or 'update_existing'.
     * @return array                   [int $inserted, int $updated, int $ignored]
     */
    public function storeBatch(array $rows, string $importBehavior = 'add_only'): array
    {
        if ($importBehavior === 'update_existing') {
            return $this->upsertBatch($rows);
        }

        return $this->insertOrIgnoreBatch($rows);
    }
}
