<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `updated` counter to contact_import_statuses.
 *
 * This column tracks how many contacts were updated (not newly inserted) during
 * an import that runs with import_behavior = 'update_existing'.  For add_only
 * imports the column stays 0.
 *
 * Rollback: drops the column, restoring the table to its prior state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_import_statuses', function (Blueprint $table) {
            // Place the column after `inserted` for logical grouping.
            $table->unsignedInteger('updated')->default(0)->after('inserted');
        });
    }

    public function down(): void
    {
        Schema::table('contact_import_statuses', function (Blueprint $table) {
            $table->dropColumn('updated');
        });
    }
};
