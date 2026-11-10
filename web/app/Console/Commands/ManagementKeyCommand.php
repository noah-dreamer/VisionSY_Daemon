<?php
namespace App\Console\Commands;

use App\Models\ManagementApiKey;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class ManagementKeyCommand extends Command
{
    protected $signature = 'visionsy:management-key {email : 绑定的系统管理员邮箱} {--name=Dashboard} {--revoke= : 撤销已有密钥前缀} {--json}';
    protected $description = '创建独立控制台的管理 API 密钥（仅显示一次），或按前缀撤销';
    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user || ! $user->isSystemAdmin() || ! $user->isActive()) {
            $this->error('需要一个已激活的系统管理员账号。');
            return self::FAILURE;
        }
        if ($prefix = $this->option('revoke')) {
            $count = ManagementApiKey::where('user_id', $user->id)->where('prefix', $prefix)->update(['revoked_at' => now()]);
            if ($count) {
                $audit->log('management.key_revoked', $user, 'management_api_key', null, ['prefix' => $prefix]);
            }
            $this->info($count ? '密钥已撤销。' : '未找到密钥。');
            return $count ? self::SUCCESS : self::FAILURE;
        }
        $name = (string) $this->option('name');
        if (mb_strlen($name) > 80 || $name === '') {
            $this->error('密钥名称应为 1–80 字符。');
            return self::FAILURE;
        }
        $issued = ManagementApiKey::issue($user, $name);
        $audit->log('management.key_created', $user, 'management_api_key', (string) $issued['key']->id, ['name' => $name]);
        $result = ['token' => $issued['token'], 'prefix' => $issued['key']->prefix, 'name' => $name];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));
        } else {
            $this->warn('请将密钥粘贴到 Dashboard 连接页面；不要发送给他人。密钥仅显示一次。');
            $this->line($issued['token']);
        }
        return self::SUCCESS;
    }
}
