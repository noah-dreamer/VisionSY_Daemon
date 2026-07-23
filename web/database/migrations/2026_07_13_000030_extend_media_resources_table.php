<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->foreignId('storage_source_id')->nullable()->after('id')
                ->constrained('media_storage_sources')->nullOnDelete();
            $table->foreignId('gallery_id')->nullable()->after('storage_source_id')
                ->constrained('media_galleries')->nullOnDelete();
            $table->string('relative_path', 1024)->nullable()->after('gallery_id');

            $table->unsignedBigInteger('source_size_bytes')->nullable()->after('size_bytes');
            $table->dateTime('source_modified_at')->nullable()->after('source_size_bytes');
            $table->string('source_identity', 64)->nullable()->after('source_modified_at');

            $table->boolean('is_raw')->default(false)->after('source_identity');
            $table->boolean('is_system_managed')->default(false)->after('is_raw');

            $table->string('index_status', 20)->default('active')->after('status');
            $table->string('derivative_status', 20)->nullable()->after('index_status');
            $table->text('derivative_error')->nullable()->after('derivative_status');

            $table->dateTime('missing_at')->nullable()->after('derivative_error');
            $table->dateTime('last_seen_at')->nullable()->after('missing_at');

            // 不为 (storage_source_id, relative_path) 建组合索引：relative_path 为 varchar(1024)，
            // 在 MySQL/MariaDB utf8mb4 下超过 InnoDB 3072 字节索引上限（SQLite 无此限制，但需跨库一致）。
            // 资源身份改由 (gallery_id, relative_path_hash) 唯一索引承载（见 2026_07_22_000040）；
            // storage_source_id 单列查询已由外键自带索引覆盖。
            $table->index('gallery_id');
            $table->index('index_status');
        });
    }

    public function down(): void
    {
        Schema::table('media_resources', function (Blueprint $table) {
            $table->dropForeign(['storage_source_id']);
            $table->dropForeign(['gallery_id']);
            $table->dropColumn([
                'storage_source_id', 'gallery_id', 'relative_path',
                'source_size_bytes', 'source_modified_at', 'source_identity',
                'is_raw', 'is_system_managed',
                'index_status', 'derivative_status', 'derivative_error',
                'missing_at', 'last_seen_at',
            ]);
        });
    }
};
