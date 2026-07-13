<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\CampusIpRange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusActivationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(): User
    {
        return User::factory()->pendingActivation()->create();
    }

    private function login(User $user, array $headers = []): void
    {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
        ], $headers);
    }

    public function test_pending_user_is_activated_when_login_from_campus_cidr(): void
    {
        CampusIpRange::create(['cidr' => '10.20.0.0/16', 'description' => '教学区', 'enabled' => true]);

        $user = $this->pendingUser();

        // 测试环境可信代理为 127.0.0.1（REMOTE_ADDR 默认值），X-Real-IP 因此被信任
        $this->login($user, ['X-Real-IP' => '10.20.3.7']);

        $user->refresh();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame('10.20.3.7', $user->activation_ip);
        $this->assertNotNull($user->activated_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.activated_by_campus_ip',
            'target_id' => (string) $user->id,
        ]);
    }

    public function test_pending_user_is_not_activated_from_external_ip(): void
    {
        CampusIpRange::create(['cidr' => '10.20.0.0/16', 'enabled' => true]);

        $user = $this->pendingUser();

        $this->login($user, ['X-Real-IP' => '203.0.113.10']);

        $user->refresh();
        $this->assertSame(UserStatus::PendingActivation, $user->status);
        $this->assertNull($user->activated_at);
    }

    public function test_disabled_cidr_does_not_activate(): void
    {
        CampusIpRange::create(['cidr' => '10.20.0.0/16', 'enabled' => false]);

        $user = $this->pendingUser();

        $this->login($user, ['X-Real-IP' => '10.20.3.7']);

        $this->assertSame(UserStatus::PendingActivation, $user->fresh()->status);
    }

    public function test_forged_x_real_ip_from_untrusted_source_does_not_activate(): void
    {
        CampusIpRange::create(['cidr' => '10.20.0.0/16', 'enabled' => true]);

        $user = $this->pendingUser();

        // 请求来源不是可信代理（REMOTE_ADDR 改为公网地址），伪造的 X-Real-IP 必须被忽略
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'test-only-password',
            ], ['X-Real-IP' => '10.20.3.7']);

        $this->assertSame(UserStatus::PendingActivation, $user->fresh()->status);
    }

    public function test_trusted_proxy_header_activates_via_recheck_endpoint(): void
    {
        CampusIpRange::create(['cidr' => '10.30.0.0/16', 'enabled' => true]);

        $user = $this->pendingUser();

        $this->actingAs($user)
            ->post('/dashboard/recheck-activation', [], ['X-Real-IP' => '10.30.1.1'])
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame('10.30.1.1', $user->activation_ip);
    }

    public function test_dashboard_shows_detected_ip_for_pending_user(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)
            ->get('/dashboard', ['X-Real-IP' => '198.51.100.4'])
            ->assertOk()
            ->assertSee('请连接校园网后登录以激活账号')
            ->assertSee('198.51.100.4');
    }
}
