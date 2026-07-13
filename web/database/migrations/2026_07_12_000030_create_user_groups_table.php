<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户组（媒体库权限组）——对齐 ResourceSpace 的 usergroup 模型：
 * RS 用 name + permissions 权限串（s/w/T/c/e0/a…）+ 高级选项
 * （下载限额、IP 限制、注册可选组）；这里以具名布尔权限位实现同一套语义，
 * 由官网后台统一管理，与账号体系同库不割裂。用户未指定组时回退到角色默认矩阵。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();

            // —— 权限位（括号内为对应的 ResourceSpace 权限）——
            $table->boolean('can_browse')->default(true);                // s：可检索/浏览
            $table->boolean('can_preview')->default(true);               // w：可看带水印预览
            $table->boolean('can_download_watermarked')->default(true);  // 允许下载水印压缩图
            $table->boolean('can_download_original')->default(false);    // 无 T 限制：可下无水印原图
            $table->boolean('can_upload')->default(false);               // c：可创建资源/上传
            $table->boolean('can_manage_media')->default(false);         // e0 + 资源管理：编辑/归档/删除全部素材

            // —— 高级选项（对齐 RS Advanced user group options）——
            $table->unsignedInteger('download_limit_per_day')->default(0); // Resource download limit，0=不限
            $table->string('ip_restriction', 255)->nullable();             // IP address restriction（CIDR，逗号分隔）
            $table->boolean('is_registration_default')->default(false);    // 新注册用户自动加入

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
