<?php
namespace App\Services;

use App\Models\ServiceSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class MailConfiguration
{
    private array $environment;
    public function __construct()
    {
        $smtp = config('mail.mailers.smtp');
        $this->environment = [
            'enabled' => config('mail.default') === 'smtp',
            'host' => $smtp['host'] ?? '', 'port' => (int) ($smtp['port'] ?? 587),
            'security' => ($smtp['scheme'] ?? null) === 'smtps' || (int) ($smtp['port'] ?? 0) === 465 ? 'smtps' : 'starttls',
            'username' => $smtp['username'] ?? '', 'password' => $smtp['password'] ?? '',
            'from_address' => config('mail.from.address'), 'from_name' => config('mail.from.name'),
        ];
    }
    public function current(): array
    {
        if (! Schema::hasTable('service_settings')) {
            return $this->environment;
        }
        return ServiceSetting::find('mail')?->value ?? $this->environment;
    }
    public function publicSettings(): array
    {
        $settings = $this->current();
        $settings['has_password'] = ! empty($settings['password']);
        unset($settings['password']);
        return $settings;
    }
    public function save(array $data): void
    {
        $previous = $this->current();
        $data['password'] = ! empty($data['password']) ? $data['password'] : ($previous['password'] ?? '');
        if ($data['clear_password'] ?? false) {
            $data['password'] = '';
        }
        unset($data['clear_password']);
        ServiceSetting::updateOrCreate(['key' => 'mail'], ['value' => $data]);
    }
    public function prepare(): void
    {
        $settings = $this->current();
        if (! $settings['enabled']) {
            if (app()->isProduction()) {
                throw new MailDeliveryException('网站尚未开通邮件发送，请联系管理员后再试。');
            }
            return; // Isolated tests use the fake/array transport explicitly.
        }
        config(['mail.default' => 'visionsy_smtp', 'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'], 'mail.mailers.visionsy_smtp' => [
                'transport' => 'smtp', 'scheme' => $settings['security'] === 'smtps' ? 'smtps' : 'smtp',
                'host' => $settings['host'], 'port' => $settings['port'],
                'username' => $settings['username'] ?: null, 'password' => $settings['password'] ?: null,
                'auto_tls' => $settings['security'] !== 'none', 'require_tls' => $settings['security'] === 'starttls',
                'timeout' => 10,
            ]]);
        Mail::purge('visionsy_smtp');
    }
}
