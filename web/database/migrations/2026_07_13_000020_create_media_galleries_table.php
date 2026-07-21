<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storage_source_id')->constrained('media_storage_sources')->cascadeOnDelete();
            $table->string('relative_path', 1024);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->dateTime('event_started_at')->nullable()->index();
            $table->dateTime('event_ended_at')->nullable();
            $table->foreignId('cover_media_resource_id')->nullable()->constrained('media_resources')->nullOnDelete();
            $table->boolean('is_managed')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->string('scan_status', 20)->default('pending');
            $table->timestamp('last_scanned_at')->nullable();
            $table->unsignedInteger('media_count')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['storage_source_id', 'relative_path']);
            $table->index(['storage_source_id', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_galleries');
    }
};
