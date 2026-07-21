<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_storage_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('driver', 20)->default('local');
            $table->string('root_path', 1024);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_read_only')->default(true);
            $table->boolean('is_managed')->default(false);
            $table->boolean('scan_recursive')->default(true);
            $table->timestamp('last_scan_started_at')->nullable();
            $table->timestamp('last_scan_finished_at')->nullable();
            $table->string('last_scan_status', 20)->nullable();
            $table->text('last_scan_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_storage_sources');
    }
};
