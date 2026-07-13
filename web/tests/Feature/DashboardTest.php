<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_active_user_sees_enter_media_library_button(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('进入媒体库')
            ->assertSee('https://media.example.com');
    }

    public function test_disabled_user_sees_disabled_notice(): void
    {
        $user = User::factory()->disabled()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('账号已被禁用')
            ->assertDontSee('进入媒体库');
    }

    public function test_pending_email_user_is_prompted_to_verify(): void
    {
        $user = User::factory()->pendingEmailVerification()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('请先验证邮箱');
    }

    public function test_home_page_renders_brand(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('VisionSY')
            ->assertSee('分级下载')
            ->assertSee('校园 IP 激活');
    }
}
