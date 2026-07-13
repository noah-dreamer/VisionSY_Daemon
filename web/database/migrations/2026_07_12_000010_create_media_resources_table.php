<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 内置媒体库（DAM）资源表。
 * 原图存 storage/app/protected/media/{dir}/original.{ext}，
 * 派生图（缩略图 / 带水印预览）由 ImageDerivativeService 在上传时生成。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_resources', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->json('tags')->nullable();

            $table->string('type', 16)->index();            // image / video / other
            $table->string('status', 16)->default('active')->index();

            $table->string('original_name', 255);
            $table->string('original_ext', 16);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64)->index(); // 重复素材提示
            // —— 内嵌元数据（对齐 ResourceSpace：上传时读取文件全部标签）——
            $table->json('metadata')->nullable();          // 全部标签（分组），详情页"全部元数据"
            $table->dateTime('taken_at')->nullable();      // 拍摄时间（EXIF DateTimeOriginal / 容器 CreateDate）
            $table->string('camera', 120)->nullable();     // 相机（Make + Model）
            $table->decimal('duration_seconds', 10, 3)->nullable(); // 视频/音频时长

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('storage_dir', 128)->unique();   // 相对 media/ 的目录
            $table->boolean('has_thumb')->default(false);
            $table->boolean('has_preview')->default(false);

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('download_count_original')->default(0);
            $table->unsignedBigInteger('download_count_watermarked')->default(0);

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_resources');
    }
};
