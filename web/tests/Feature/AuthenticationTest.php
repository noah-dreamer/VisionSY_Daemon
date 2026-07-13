<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'display_name' => '张同学',
            'email' => 'student@example.edu.cn',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'student@example.edu.cn')->firstOrFail();
        $this->assertSame(UserStatus::PendingEmailVerification, $user->status);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmail::class);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered', 'target_id' => (string) $user->id]);
    }

    public function test_email_verification_moves_user_to_pending_activation(): void
    {
        $user = User::factory()->pendingEmailVerification()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(UserStatus::PendingActivation, $user->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.email_verified']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_succeeds_and_records_last_login(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'nope']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'nope']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('过于频繁', session('errors')->first('email'));
    }

    public function test_disabled_user_cannot_login(): void
    {
        $user = User::factory()->disabled()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.login_blocked_disabled']);
    }
}
