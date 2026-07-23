<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户组（媒体库权限组）。以具名布尔权限位实现权限管理，
 * 由官网后台统一管理，与账号体系同库。用户未指定组时回退到角色默认矩阵。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();

            // —— 权限位 ——
            $table->boolean('can_browse')->default(true);
            $table->boolean('can_preview')->default(true);
            $table->boolean('can_download_watermarked')->default(true);
            $table->boolean('can_download_original')->default(false);
            $table->boolean('can_upload')->default(false);
            $table->boolean('can_manage_media')->default(false);

            // —— 高级选项 ——
            $table->unsignedInteger('download_limit_per_day')->default(0);
            $table->string('ip_restriction', 255)->nullable();
            $table->boolean('is_registration_default')->default(false);

            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('user_group_id')->nullable()->after('role')
                ->constrained('user_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_group_id');
        });
        Schema::dropIfExists('user_groups');
    }
};
