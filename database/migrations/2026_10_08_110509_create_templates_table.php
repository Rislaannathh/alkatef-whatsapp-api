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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();;
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->string('language', 20)->default('en');
            $table->foreignId('category')->nullable()->constrained('categories')->onDelete('set null');
            $table->string('media')->nullable();
            $table->enum('media_type', ['image', 'video', 'document'])->nullable();
            $table->text('header')->nullable();
            $table->text('body')->nullable();
            $table->text('footer')->nullable();
            $table->string('button', 150)->nullable();
            $table->enum('button_action_type', ['url', 'phone', 'quick_reply'])->nullable();
            $table->text('button_value')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected', 'inactive'])->default('draft');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
