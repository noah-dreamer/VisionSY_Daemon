<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Captcha\PlayCaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlayCaptcha（github.com/mortspace/playcaptcha 抓娃娃机）服务端配套层测试。
 * 上游组件是纯前端交互，服务端负责：一次性挑战 id、人类耗时下限、一次性 pass token。
 */
class PlayCaptchaTest extends TestCase
{
    use RefreshDatabase;

    public function test_challenge_endpoint_returns_one_time_id(): void
    {
        $this->getJson('/captcha/challenge')
            ->assertOk()
            ->assertJsonStructure(['id', 'ttl']);
    }

    public function test_verify_issues_one_time_pass_token(): void
    {
        // 测试环境 min_solve_seconds=0（phpunit.xml），即时通关即可
        $service = app(PlayCaptchaService::class);
        $challenge = $service->createChallenge();

        $response = $this->postJson('/captcha/verify', ['id' => $challenge['id']]);
        $response->assertOk()->assertJson(['ok' => true]);

        $token = $response->json('token');
        $this->assertTrue($service->consumePass($token));
        // pass token 一次性
        $this->assertFalse($service->consumePass($token));
    }

    public function test_challenge_id_is_one_time(): void
    {
        $challenge = app(PlayCaptchaService::class)->createChallenge();

        $this->postJson('/captcha/verify', ['id' => $challenge['id']])->assertOk();
        // 同一挑战重放被拒绝
        $this->postJson('/captcha/verify', ['id' => $challenge['id']])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'challenge_expired']);
    }

    public function test_solving_faster_than_human_minimum_is_rejected(): void
    {
        config(['visionsy.captcha.min_solve_seconds' => 5]);

        $challenge = app(PlayCaptchaService::class)->createChallenge();

        $this->postJson('/captcha/verify', ['id' => $challenge['id']])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'too_fast']);
    }

    public function test_unknown_challenge_is_rejected(): void
    {
        $this->postJson('/captcha/verify', ['id' => 'no-such-challenge'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'challenge_expired']);
    }

    public function test_login_requires_captcha_token_when_enabled(): void
    {
        config(['visionsy.captcha.enabled' => true]);

        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
        ])->assertSessionHasErrors('captcha');

        $this->assertGuest();
    }

    public function test_login_succeeds_with_valid_captcha_pass_token(): void
    {
        config(['visionsy.captcha.enabled' => true]);

        $service = app(PlayCaptchaService::class);
        $challenge = $service->createChallenge();
        $result = $service->verify($challenge['id']);
        $this->assertTrue($result['ok']);

        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
            'captcha_token' => $result['token'],
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_pass_token_cannot_be_reused_for_second_login(): void
    {
        config(['visionsy.captcha.enabled' => true]);

        $service = app(PlayCaptchaService::class);
        $result = $service->verify($service->createChallenge()['id']);

        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
            'captcha_token' => $result['token'],
        ])->assertRedirect(route('dashboard'));

        $this->post('/logout');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'test-only-password',
            'captcha_token' => $result['token'],
        ])->assertSessionHasErrors('captcha');
    }
}
