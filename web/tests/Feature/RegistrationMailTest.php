<?php
namespace Tests\Feature;

use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\ManagementApiKey;
use App\Models\ServiceSetting;
use App\Models\User;
use App\Services\EmailVerificationCodeService;
use App\Services\MailConfiguration;
use App\Services\MailDeliveryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationMailTest extends TestCase
{
    use RefreshDatabase;
    private function registration(): array
    {
        return ['display_name' => '注册测试用户', 'email' => 'registration@example.test', 'password' => 'test-only-password', 'password_confirmation' => 'test-only-password'];
    }
    private function smtp(): array
    {
        return ['enabled' => true, 'host' => '127.0.0.1', 'port' => 1, 'security' => 'none',
            'username' => 'sender@example.test', 'password' => 'test-smtp-only-secret',
            'from_address' => 'sender@example.test', 'from_name' => 'VisionSY'];
    }
    private function admin(): void
    {
        $owner = User::factory()->systemAdmin()->create();
        $this->withHeader('Authorization', 'Bearer '.ManagementApiKey::issue($owner, 'Mail tests')['token']);
    }
    public function test_production_registration_with_log_mailer_does_not_claim_delivery_or_strand_an_account(): void
    {
        config(['mail.default' => 'log']);
        $this->app['env'] = 'production';
        try {
            $this->withSession(['_token' => 'registration-csrf'])->from('/register')->post('/register',
                $this->registration() + ['_token' => 'registration-csrf'])->assertRedirect('/register')->assertSessionHasErrors('email');
            $this->assertDatabaseMissing('users', ['email' => 'registration@example.test']);
            $this->assertDatabaseCount('email_verification_codes', 0);
            $this->assertGuest();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
    public function test_failed_delivery_rolls_back_new_registration_and_password_is_not_flashed(): void
    {
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('private-smtp-error-with-credential'));
        $response = $this->from('/register')->post('/register', $this->registration());
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'registration@example.test']);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertNull(session()->getOldInput('password'));
        $this->assertStringNotContainsString('private-smtp-error-with-credential', session('errors')->first('email'));
    }
    public function test_failed_resend_keeps_the_previous_code_and_does_not_start_a_new_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->pendingEmailVerification()->create();
        $codes = app(EmailVerificationCodeService::class);
        $codes->issue($user);
        $old = EmailVerificationCode::where('user_id', $user->id)->sole();
        $this->travel(61)->seconds();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('test transport failure'));
        try {
            $codes->issue($user);
            $this->fail('Delivery must fail');
        } catch (MailDeliveryException $error) {
            $this->assertSame($old->code_hash, EmailVerificationCode::where('user_id', $user->id)->sole()->code_hash);
            $this->assertSame(0, $codes->resendAvailableIn($user));
        }
    }
    public function test_duplicate_display_name_has_a_validation_error_instead_of_a_database_error(): void
    {
        Mail::fake();
        User::factory()->create(['display_name' => '注册测试用户']);
        $this->post('/register', $this->registration())->assertSessionHasErrors('display_name');
        Mail::assertNothingSent();
    }
    public function test_mail_settings_require_management_authentication_and_never_return_the_password(): void
    {
        $this->getJson('/api/management/v1/mail')->assertUnauthorized();
        $this->admin();
        $this->putJson('/api/management/v1/mail', $this->smtp())->assertOk()->assertJsonPath('data.has_password', true)->assertJsonMissingPath('data.password');
        $raw = ServiceSetting::find('mail')->getRawOriginal('value');
        $this->assertStringNotContainsString('test-smtp-only-secret', $raw);
        $data = $this->smtp(); unset($data['password']);
        $this->putJson('/api/management/v1/mail', $data)->assertOk();
        $this->assertSame('test-smtp-only-secret', app(MailConfiguration::class)->current()['password']);
        $this->assertStringNotContainsString('test-smtp-only-secret', $this->getJson('/api/management/v1/mail')->getContent());
    }
    public function test_test_mail_connection_failure_is_actionable_without_exposing_credentials(): void
    {
        $this->admin();
        $this->putJson('/api/management/v1/mail', $this->smtp())->assertOk();
        $response = $this->postJson('/api/management/v1/mail/test', ['recipient' => 'recipient@example.test'])->assertUnprocessable();
        $this->assertStringNotContainsString('test-smtp-only-secret', $response->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'mail.test_failed']);
    }
    public function test_disabled_mail_settings_cannot_claim_a_successful_test(): void
    {
        $this->admin();
        $this->postJson('/api/management/v1/mail/test', ['recipient' => 'recipient@example.test'])->assertUnprocessable();
    }
}
