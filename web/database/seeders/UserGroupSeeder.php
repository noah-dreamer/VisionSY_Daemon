<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Seeder;

/**
 * 默认用户组。
 * 组权限位覆盖角色默认矩阵；未入组用户按角色回退。
 */
class UserGroupSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as $attrs) {
            UserGroup::updateOrCreate(['name' => $attrs['name']], $attrs);
        }

        // 演示分组：宣传部成员 → 原图供稿组；普通学生 → 普通成员组
        User::where('email', 'media@example.com')->update([
            'user_group_id' => UserGroup::where('name', '原图供稿组')->value('id'),
        ]);
        User::where('email', 'student@student.example.com')->update([
            'user_group_id' => UserGroup::where('name', '普通成员组')->value('id'),
        ]);

        $this->command?->info('默认用户组已创建（4 组，含注册默认组与受限演示组）。');
    }

    public static function defaults(): array
    {
        return [
            [
                'name' => '资源管理组',
                'description' => '对应 RS Archivists：管理全部素材（编辑/归档/删除）+ 原图下载 + 上传',
                'can_browse' => true, 'can_preview' => true, 'can_download_watermarked' => true,
                'can_download_original' => true, 'can_upload' => true, 'can_manage_media' => true,
            ],
            [
                'name' => '原图供稿组',
                'description' => '对应 RS General staff（可上传）：上传素材、下载无水印原图',
                'can_browse' => true, 'can_preview' => true, 'can_download_watermarked' => true,
                'can_download_original' => true, 'can_upload' => true, 'can_manage_media' => false,
            ],
            [
                'name' => '普通成员组',
                'description' => '浏览 + 带水印预览与下载，不可获取原图',
                'can_browse' => true, 'can_preview' => true, 'can_download_watermarked' => true,
                'can_download_original' => false, 'can_upload' => false, 'can_manage_media' => false,
                'is_registration_default' => true,
            ],
            [
                'name' => '受限访问组',
                'description' => '对应 RS Restricted：仅浏览缩略图；演示每日限额与校园网 IP 限制',
                'can_browse' => true, 'can_preview' => false, 'can_download_watermarked' => false,
                'can_download_original' => false, 'can_upload' => false, 'can_manage_media' => false,
                'download_limit_per_day' => 20,
                'ip_restriction' => '10.0.0.0/8, 127.0.0.1/32',
            ],
        ];

    }
}
