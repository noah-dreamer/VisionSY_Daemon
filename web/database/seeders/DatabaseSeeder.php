<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 本地开发种子数据。
 *
 * 所有密码仅为本地开发占位，可通过环境变量覆盖：
 *   SEED_DEFAULT_PASSWORD
 * 生产环境禁止运行本 Seeder；生产凭据一律来自 `<SECRET_MANAGER>`。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_DEFAULT_PASSWORD', '<LOCAL_DEV_PASSWORD>');

        // 1. 系统管理员
        User::factory()->role(UserRole::SystemAdmin, '王运维')->create([
            'email' => 'admin@example.com',
            'display_name' => '系统管理员',
            'password_hash' => $password,
        ]);

        // 2. 待校园网激活的普通用户
        User::factory()->pendingActivation()->create([
            'email' => 'pending@student.example.com',
            'display_name' => '待激活同学',
            'password_hash' => $password,
        ]);

        // 3. 已激活普通用户
        User::factory()->create([
            'email' => 'student@student.example.com',
            'display_name' => '在校同学',
            'password_hash' => $password,
            'activation_ip' => '10.20.0.66',
        ]);

        // 4. 宣传部成员
        User::factory()->role(UserRole::PropagandaMember, '李拍摄')->create([
            'email' => 'media@example.com',
            'display_name' => '宣传部摄影',
            'password_hash' => $password,
        ]);

        // 5. 官微编辑老师
        User::factory()->role(UserRole::WechatEditorTeacher, '张老师')->create([
            'email' => 'teacher@example.com',
            'display_name' => '官微编辑',
            'password_hash' => $password,
        ]);

        // 6. 默认用户组（媒体库权限组）
        $this->call(UserGroupSeeder::class);

        // 9. 媒体库演示素材（GD 生成示例照片，走完整入库 + 水印流程）
        $this->call(MediaDemoSeeder::class);

        // 9. 审计日志样例
        $admin = User::where('email', 'admin@example.com')->first();
        foreach ([
            ['action' => 'admin.user_created', 'context' => ['email' => 'media@example.com', 'role' => 'propaganda_member']],
            ['action' => 'user.activated_by_intranet_probe', 'context' => ['ip' => '10.20.0.66']],
        ] as $log) {
            AuditLog::create([
                'actor_id' => $admin->id,
                'actor_email' => $admin->email,
                'action' => $log['action'],
                'target_type' => 'seed',
                'ip' => '127.0.0.1',
                'context' => $log['context'],
            ]);
        }
    }
}
