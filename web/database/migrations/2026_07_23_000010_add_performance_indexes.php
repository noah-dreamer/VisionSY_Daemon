<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_galleries', function (Blueprint $table) {
            $table->index(['is_visible', 'event_started_at', 'created_at'], 'galleries_listing_idx');
        });

        Schema::table('media_resources', function (Blueprint $table) {
            $table->index(['gallery_id', 'created_at'], 'resources_gallery_list_idx');
            $table->index(['gallery_id', 'index_status', 'created_at'], 'resources_gallery_filter_idx');
        });

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['actor_id', 'action', 'created_at'], 'audit_download_count_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('media_galleries', function (Blueprint $table) {
            $table->dropIndex('galleries_listing_idx');
        });

        Schema::table('media_resources', function (Blueprint $table) {
            $table->dropIndex('resources_gallery_list_idx');
            $table->dropIndex('resources_gallery_filter_idx');
        });

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('audit_download_count_idx');
            });
        }
    }
};
