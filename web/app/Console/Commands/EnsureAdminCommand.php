<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 幂等创建 / 修复系统管理员账号。
 *
 * 目的：为"管理员登不进去"提供一条**非破坏性**恢复路径，生产环境可安全执行。
 * 只写 users 表的一行（外加一条审计日志），不 drop、不 truncate、不调用任何 Seeder
 * ——DatabaseSeeder 会连带执行 MediaDemoSeeder 生成演示素材，生产环境绝不能跑。
 *
 * 用法：
 *   php artisan visionsy:ensure-admin admin@example.com
 *   php artisan visionsy:ensure-admin admin@example.com --password=xxxxxx
 */
class EnsureAdminCommand extends Command
{
    protected $signature = 'visionsy:ensure-admin
        {email : 管理员邮箱}
        {--password= : 明文密码；省略时随机生成并打印一次}
        {--display-name= : 显示名（数据库唯一），冲突时自动加后缀}
        {--real-name= : 实名（system_admin 角色要求实名登记）}';

    protected $description = '幂等创建或修复系统管理员账号（非破坏性，可用于生产恢复）';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("邮箱格式无效：{$email}");

            return self::FAILURE;
        }

        $plainPassword = (string) ($this->option('password') ?: Str::password(20, symbols: false));
        $isGenerated = ! $this->option('password');

        $existing = User::where('email', $email)->first();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'password_hash' => $plainPassword,
                'role' => UserRole::SystemAdmin,
                'status' => UserStatus::Active,
                'display_name' => $this->resolveDisplayName($existing),
                'real_name' => $this->option('real-name') ?: ($existing?->real_name ?: '系统管理员'),
                'email_verified_at' => $existing?->email_verified_at ?? now(),
                'activated_at' => $existing?->activated_at ?? now(),
            ]
        );

        AuditLog::create([
            'actor_id' => null,
            'actor_email' => 'console',
            'action' => $existing ? 'admin.ensure_admin_repaired' : 'admin.ensure_admin_created',
            'target_type' => 'user',
            'target_id' => (string) $user->id,
            'ip' => '127.0.0.1',
            'context' => ['email' => $email, 'password_rotated' => true],
        ]);

        $this->info(($existing ? '已修复' : '已创建')."系统管理员：{$user->email}（id={$user->id}）");
        $this->line("  角色  : {$user->role->value}");
        $this->line("  状态  : {$user->status->value}");
        $this->line("  显示名: {$user->display_name}");

        if ($isGenerated) {
            $this->newLine();
            $this->warn('随机生成的密码（仅显示这一次，请登录后立即修改）：');
            $this->line("  {$plainPassword}");
        }

        return self::SUCCESS;
    }

    /** users.display_name 有唯一索引：冲突时自动加数字后缀。 */
    private function resolveDisplayName(?User $existing): string
    {
        $wanted = (string) ($this->option('display-name') ?: $existing?->display_name ?: '系统管理员');

        $candidate = $wanted;
        $suffix = 2;

        while (
            User::where('display_name', $candidate)
                ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists()
        ) {
            $candidate = $wanted.'_'.$suffix++;
        }

        return $candidate;
    }
}
