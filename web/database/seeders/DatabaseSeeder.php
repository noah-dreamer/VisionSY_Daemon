<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CampusIpRange;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 本地开发种子数据。
 *
 * 所有密码仅为本地开发占位，可通过环境变量覆盖：
 *   SEED_DEFAULT_PASSWORD / SEED_RS_CLIENT_SECRET
 * 生产环境禁止运行本 Seeder；生产凭据一律来自 `<SECRET_MANAGER>`（见 README《凭据与密钥索引》）。
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

        // 6. 学校公网出口 IP（白名单匹配的是校内用户经 NAT 出网后、CF 透传给官网的公网 IP，
        //    不是内网 10.x 网段。下面是文档示例占位，上线请在后台替换为学校真实出口 IP）
        foreach ([
            ['cidr' => '203.0.113.10/32', 'description' => '学校公网出口 IP（示例，请替换为真实出口 IP）', 'enabled' => true],
            ['cidr' => '198.51.100.0/24', 'description' => '学校多出口公网段（多线出口时按需添加，示例）', 'enabled' => true],
            ['cidr' => '192.0.2.0/24', 'description' => '旧出口网段（停用示例）', 'enabled' => false],
        ] as $range) {
            CampusIpRange::create($range);
        }

        // 7. ResourceSpace OAuth2 客户端
        $rsSecret = env('SEED_RS_CLIENT_SECRET', '<OAUTH_CLIENT_SECRET>');
        OAuthClient::create([
            'client_id' => 'resourcespace',
            'client_secret_hash' => password_hash($rsSecret, PASSWORD_BCRYPT),
            'name' => 'ResourceSpace 媒体库',
            'redirect_uris' => [
                'https://media.example.com/plugins/visionsy_sso/pages/callback.php',
                'http://localhost:8080/plugins/visionsy_sso/pages/callback.php',
            ],
            'scopes' => ['profile'],
        ]);

        $this->command?->warn("ResourceSpace OAuth client_id=resourcespace, client_secret={$rsSecret}（仅本地开发；生产 secret 在后台重新创建并存入`<SECRET_MANAGER>`）");

        // 8. 审计日志样例
        $admin = User::where('email', 'admin@example.com')->first();
        foreach ([
            ['action' => 'admin.user_created', 'context' => ['email' => 'media@example.com', 'role' => 'propaganda_member']],
            ['action' => 'admin.campus_ip_created', 'context' => ['cidr' => '10.20.0.0/16']],
            ['action' => 'user.activated_by_campus_ip', 'context' => ['ip' => '10.20.0.66', 'cidr' => '10.20.0.0/16']],
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
