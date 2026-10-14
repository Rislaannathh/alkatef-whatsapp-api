<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the contact_import_statuses table.
     *
     * This table tracks the lifecycle of every asynchronous contact-import
     * operation that is associated with a campaign.  It is intentionally
     * separate from the campaigns table so that import progress never
     * interferes with campaign delivery counters.
     *
     * Idempotency notes
     * -----------------
     * last_processed_row  – the 1-based index of the last row that was fully
     *   processed (validated + attempted insert) in the current attempt.
     *   On retry the job starts reading from (last_processed_row + 1) so that
     *   contacts are never re-inserted and counters are never double-counted.
     *
     * retry_after_row / attempt_number are not stored here; the jobs table
     * already tracks attempts.  last_processed_row is sufficient because the
     * job resets it to 0 at the very beginning of each new attempt before
     * processing any rows.
     */
    public function up(): void
    {
        Schema::create('contact_import_statuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();

            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'completed_with_errors',
                'failed',
            ])->default('pending');

            // Total rows detected in the source (0 = not yet counted, e.g. before
            // the job starts reading a file).
            $table->unsignedInteger('total')->default(0);

            // Successfully inserted contacts in this import.
            $table->unsignedInteger('inserted')->default(0);

            // Rows that were skipped due to validation failure or duplicate phone.
            $table->unsignedInteger('failed_count')->default(0);

            // Row index (1-based) of the last fully processed row in the current
            // attempt.  Reset to 0 at the start of each new attempt.
            $table->unsignedInteger('last_processed_row')->default(0);

            // Human-readable status summary (safe for public API responses).
            $table->text('message')->nullable();

            $table->timestamps();

            // Only one import status record per campaign.
            $table->unique('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_statuses');
    }
};
