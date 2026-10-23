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
        Schema::table('templates', function (Blueprint $table) {
            $table->string('button_two', 150)->nullable()->after('button_value');
            $table->enum('button_two_action_type', ['url', 'phone', 'quick_reply'])->nullable()->after('button_two');
            $table->text('button_two_value')->nullable()->after('button_two_action_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['button_two', 'button_two_action_type', 'button_two_value']);
        });
    }
};
