<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        if (!Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('company_id')
                    ->nullable()
                    ->constrained('companies')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('users', 'created_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['company_id']);

            $table->dropColumn(['created_by', 'company_id']);
        });
    }
};
