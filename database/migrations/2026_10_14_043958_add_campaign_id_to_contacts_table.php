<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Use unsignedBigInteger for a foreign key to campaigns.id
            $table->unsignedBigInteger('campaign_id')->nullable()->after('id');

            // Optional: add foreign key constraint if campaigns table exists
            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign('campaign_id');
            $table->dropColumn('campaign_id');
        });
    }
};
