<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a unique constraint on (company_id, phone) in the contacts table.
 *
 * This constraint enables reliable upsert behaviour when import_behavior is
 * set to 'update_existing': Laravel's ON DUPLICATE KEY UPDATE needs a reliable
 * identity key to locate the row to update.
 *
 * Safety checks performed before adding the constraint:
 *  1. If the constraint already exists this migration is a no-op.
 *  2. If duplicate (company_id, phone) pairs exist the migration ABORTS with a
 *     descriptive exception so the developer can de-duplicate data first.
 *  3. NULL phones are excluded from the constraint (MySQL treats each NULL as
 *     distinct, so nullable phones never trigger the uniqueness violation).
 *
 * MySQL index / FK interaction:
 *  The existing non-unique (company_id, phone) composite index may be used by
 *  MySQL to support the contacts_company_id_foreign FK.  To replace it with a
 *  unique index we must:
 *    (a) Temporarily drop the FK.
 *    (b) Drop the non-unique composite index.
 *    (c) Add the new unique index.  MySQL will reuse this to back the FK.
 *    (d) Re-add the FK.
 *  The unique index satisfies the leftmost-prefix rule for company_id just as
 *  the old non-unique index did, so no functionality is lost.
 *
 * Rollback: reverses the above steps — drops unique index, restores non-unique
 * composite index, and recreates the FK.
 */
return new class extends Migration
{
    /** The unique constraint name we will create. */
    private const UNIQUE_INDEX   = 'contacts_company_phone_unique';

    /** The non-unique index name that may already exist. */
    private const COMPOSITE_IDX  = 'contacts_company_id_phone_index';

    /** The FK that uses company_id as its column. */
    private const COMPANY_FK     = 'contacts_company_id_foreign';

    public function up(): void
    {
        // ── 1. Already applied? ───────────────────────────────────────────────
        $indexNames = $this->indexNames();

        if (in_array(self::UNIQUE_INDEX, $indexNames, true)) {
            return;
        }

        // ── 2. Check for duplicates before adding a unique constraint ─────────
        $duplicates = DB::select("
            SELECT company_id, phone, COUNT(*) AS cnt
            FROM contacts
            WHERE phone IS NOT NULL
            GROUP BY company_id, phone
            HAVING cnt > 1
            LIMIT 1
        ");

        if (! empty($duplicates)) {
            $d = $duplicates[0];
            throw new \RuntimeException(
                "Cannot add unique constraint " . self::UNIQUE_INDEX . ": " .
                "duplicate rows detected (company_id={$d->company_id}, phone={$d->phone}, count={$d->cnt}). " .
                "Remove or merge the duplicate contacts first, then re-run the migration."
            );
        }

        // ── 3. Drop FK that depends on the composite index ────────────────────
        $fkNames = $this->fkNames();

        if (in_array(self::COMPANY_FK, $fkNames, true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropForeign(self::COMPANY_FK);
            });
        }

        // ── 4. Drop the old non-unique composite index ────────────────────────
        if (in_array(self::COMPOSITE_IDX, $this->indexNames(), true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropIndex(self::COMPOSITE_IDX);
            });
        }

        // ── 5. Add the unique constraint (replaces the composite index) ───────
        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['company_id', 'phone'], self::UNIQUE_INDEX);
        });

        // ── 6. Restore the FK — MySQL can now use the unique index for it ─────
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('company_id', self::COMPANY_FK)
                ->references('id')
                ->on('companies')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $indexNames = $this->indexNames();
        $fkNames    = $this->fkNames();

        // Drop FK first.
        if (in_array(self::COMPANY_FK, $fkNames, true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropForeign(self::COMPANY_FK);
            });
        }

        // Drop unique index.
        if (in_array(self::UNIQUE_INDEX, $this->indexNames(), true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        // Restore non-unique composite index.
        if (! in_array(self::COMPOSITE_IDX, $this->indexNames(), true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index(['company_id', 'phone'], self::COMPOSITE_IDX);
            });
        }

        // Restore FK.
        if (! in_array(self::COMPANY_FK, $this->fkNames(), true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->foreign('company_id', self::COMPANY_FK)
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            });
        }
    }


    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Return the current database driver name ('mysql', 'sqlite', etc.). */
    private function driver(): string
    {
        return DB::getDriverName();
    }

    /**
     * Return the current list of index names on the contacts table.
     *
     * Uses SHOW INDEX on MySQL/MariaDB and Schema::getIndexes() on SQLite.
     */
    private function indexNames(): array
    {
        if ($this->driver() === 'mysql') {
            $rows = DB::select('SHOW INDEX FROM contacts');
            return array_unique(array_column($rows, 'Key_name'));
        }

        // SQLite / other: use the cross-driver Schema API.
        $indexes = Schema::getIndexes('contacts');
        return array_unique(array_column($indexes, 'name'));
    }

    /**
     * Return the current list of FK constraint names on the contacts table.
     *
     * SQLite technically supports FKs but does not expose them via
     * information_schema.  We return an empty array there since this migration
     * does not need to manipulate FKs on SQLite.
     */
    private function fkNames(): array
    {
        if ($this->driver() !== 'mysql') {
            return []; // SQLite FK management is a no-op in this migration.
        }

        $rows = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'contacts'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        return array_column($rows, 'CONSTRAINT_NAME');
    }
};
