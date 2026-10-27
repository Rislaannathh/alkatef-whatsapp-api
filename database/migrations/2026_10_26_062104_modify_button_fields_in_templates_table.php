<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure any existing non-null button values can be converted to valid JSON in MySQL
        DB::table('templates')
            ->whereNotNull('button')
            ->get()
            ->each(function ($template) {
                if (! is_null($template->button)) {
                    json_decode($template->button);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        DB::table('templates')->where('id', $template->id)->update([
                            'button' => json_encode($template->button),
                        ]);
                    }
                }
            });

        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn([
                'button_action_type',
                'button_value',
                'button_two',
                'button_two_action_type',
                'button_two_value',
            ]);
            $table->json('button')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('button', 150)->nullable()->change();
            $table->enum('button_action_type', ['url', 'phone', 'quick_reply'])->nullable()->after('button');
            $table->text('button_value')->nullable()->after('button_action_type');
            $table->string('button_two', 150)->nullable()->after('button_value');
            $table->enum('button_two_action_type', ['url', 'phone', 'quick_reply'])->nullable()->after('button_two');
            $table->text('button_two_value')->nullable()->after('button_two_action_type');
        });
    }
};
