<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * 1. Drop existing foreign keys only when present.
         */
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'contacts'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        $existingForeignKeys = array_column(
            $foreignKeys,
            'CONSTRAINT_NAME'
        );

        Schema::table('contacts', function (Blueprint $table) use ($existingForeignKeys) {
            foreach (['category_id', 'created_by'] as $column) {
                $constraint = "contacts_{$column}_foreign";

                if (in_array($constraint, $existingForeignKeys, true)) {
                    $table->dropForeign($constraint);
                }
            }
        });

        /*
         * 2. Drop old unique indexes only when present.
         */
        $indexes = DB::select("
            SHOW INDEX FROM contacts
        ");

        $uniqueIndexes = [];

        foreach ($indexes as $index) {
            if ((int) $index->Non_unique === 0) {
                $uniqueIndexes[$index->Key_name] = true;
            }
        }

        Schema::table('contacts', function (Blueprint $table) use ($uniqueIndexes) {
            foreach ([
                'contacts_phone_unique',
                'contacts_email_unique',
                'contacts_phone_email_unique',
            ] as $indexName) {
                if (isset($uniqueIndexes[$indexName])) {
                    $table->dropUnique($indexName);
                }
            }
        });

        /*
         * 3. Remove obsolete columns if they exist.
         */
        $obsoleteColumns = [];

        foreach (['category_id', 'company', 'notes'] as $column) {
            if (Schema::hasColumn('contacts', $column)) {
                $obsoleteColumns[] = $column;
            }
        }

        if (!empty($obsoleteColumns)) {
            Schema::table('contacts', function (Blueprint $table) use ($obsoleteColumns) {
                $table->dropColumn($obsoleteColumns);
            });
        }

        /*
         * 4. Add company_id if missing.
         */
        if (!Schema::hasColumn('contacts', 'company_id')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id');

                $table->index(
                    ['company_id', 'phone'],
                    'contacts_company_id_phone_index'
                );
            });
        }

        /*
         * 5. Add created_by if missing.
         */
        if (!Schema::hasColumn('contacts', 'created_by')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('company_id');
            });
        }

        /*
         * 6. Add foreign keys only when missing.
         */
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'contacts'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        $existingForeignKeys = array_column(
            $foreignKeys,
            'CONSTRAINT_NAME'
        );

        Schema::table('contacts', function (Blueprint $table) use ($existingForeignKeys) {
            if (!in_array('contacts_company_id_foreign', $existingForeignKeys, true)) {
                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            }

            if (!in_array('contacts_created_by_foreign', $existingForeignKeys, true)) {
                $table->foreign('created_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        /*
         * 7. Update existing columns.
         */
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->change();
            $table->string('phone', 30)->nullable()->change();
            $table->string('email', 150)->nullable()->change();

            $table->enum('status', [
                'active',
                'inactive',
                'blocked',
            ])->default('active')->change();
        });

        /*
         * 8. Add remaining columns if missing.
         */
        if (!Schema::hasColumn('contacts', 'country_code')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('country_code', 10)
                    ->nullable()
                    ->after('name');
            });
        }

        if (!Schema::hasColumn('contacts', 'data')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->json('data')
                    ->nullable()
                    ->after('email');
            });
        }

        /*
         * 9. Ensure company_id + phone index exists.
         */
        $indexes = DB::select("SHOW INDEX FROM contacts");

        $indexNames = array_unique(array_map(
            fn ($index) => $index->Key_name,
            $indexes
        ));

        if (!in_array('contacts_company_id_phone_index', $indexNames, true)) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index(
                    ['company_id', 'phone'],
                    'contacts_company_id_phone_index'
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
         * Rollback is intentionally conservative because this migration
         * removes old columns and indexes that may contain user data.
         */
        throw new RuntimeException(
            'Automatic rollback is disabled for this migration because it removes existing contact data. Restore from a database backup or write a schema-specific rollback.'
        );
    }
};
