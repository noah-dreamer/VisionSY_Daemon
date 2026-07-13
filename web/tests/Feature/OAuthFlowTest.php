<?php

namespace Tests\Feature;

use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'https://media.example.com/plugins/visionsy_sso/pages/callback.php';
    private const CLIENT_SECRET = 'test-only-oauth-secret';

    private function makeClient(): OAuthClient
    {
        return OAuthClient::create([
            'client_id' => 'resourcespace',
            'client_secret_hash' => password_hash(self::CLIENT_SECRET, PASSWORD_BCRYPT),
            'name' => 'ResourceSpace 媒体库',
            'redirect_uris' => [self::REDIRECT_URI],
            'scopes' => ['profile'],
        ]);
    }

    private function authorizeAndGetCode(User $user): string
    {
        $response = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => 'resourcespace',
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'state' => 'xyz',
        ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('code', $query);
        $this->assertSame('xyz', $query['state']);

        return $query['code'];
    }

    private function exchange(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => 'resourcespace',
            'client_secret' => self::CLIENT_SECRET,
        ]);
    }

    public function test_authorize_issues_code_for_active_user(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $code = $this->authorizeAndGetCode($user);

        $this->assertDatabaseHas('oauth_authorization_codes', [
            'code_hash' => hash('sha256', $code),
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'oauth.code_issued']);
    }

    public function test_invalid_redirect_uri_is_not_redirected(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => 'resourcespace',
            'redirect_uri' => 'https://evil.example.com/callback',
            'response_type' => 'code',
        ]))->assertStatus(400);
    }

    public function test_code_exchanges_for_tokens(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $code = $this->authorizeAndGetCode($user);

        $response = $this->exchange($code);

        $response->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'expires_in',
        ]);
        $this->assertSame('Bearer', $response->json('token_type'));
    }

    public function test_code_cannot_be_reused(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $code = $this->authorizeAndGetCode($user);

        $this->exchange($code)->assertOk();
        $this->exchange($code)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $code = $this->authorizeAndGetCode($user);

        OAuthAuthorizationCode::query()->update(['expires_at' => now()->subMinute()]);

        $this->exchange($code)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_wrong_client_secret_is_rejected(): void
    {
        $this->makeClient();
        $user = User::factory()->create();
        $code = $this->authorizeAndGetCode($user);

        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => 'resourcespace',
            'client_secret' => 'wrong-secret',
        ])->assertStatus(401)->assertJson(['error' => 'invalid_client']);
    }

    public function test_userinfo_returns_role_and_groups(): void
    {
        $this->makeClient();
        $user = User::factory()->role(\App\Enums\UserRole::PropagandaMember, '李宣传')->create();

        $code = $this->authorizeAndGetCode($user);
        $accessToken = $this->exchange($code)->json('access_token');

        $response = $this->getJson('/oauth/userinfo', ['Authorization' => 'Bearer '.$accessToken]);

        $response->assertOk()->assertJson([
            'sub' => (string) $user->id,
            'email' => $user->email,
            'real_name' => '李宣传',
            'role' => 'propaganda_member',
            'groups' => ['visionsy_propaganda_member'],
            'status' => 'active',
            'real_name_required' => false,
        ]);
    }

    public function test_expired_access_token_is_rejected(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $code = $this->authorizeAndGetCode($user);
        $accessToken = $this->exchange($code)->json('access_token');

        \App\Models\OAuthAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/oauth/userinfo', ['Authorization' => 'Bearer '.$accessToken])
            ->assertStatus(401);
    }

    public function test_pending_activation_user_cannot_sso(): void
    {
        $this->makeClient();
        $user = User::factory()->pendingActivation()->create();

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => 'resourcespace',
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
        ]))->assertStatus(403)->assertSee('请连接校园网后登录以激活账号');

        $this->assertDatabaseHas('audit_logs', ['action' => 'oauth.authorize_denied']);
    }

    public function test_disabled_user_cannot_sso(): void
    {
        $this->makeClient();
        $user = User::factory()->disabled()->create();

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => 'resourcespace',
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
        ]))->assertStatus(403);
    }

    public function test_refresh_token_rotation(): void
    {
        $this->makeClient();
        $user = User::factory()->create();

        $code = $this->authorizeAndGetCode($user);
        $first = $this->exchange($code)->json();

        $second = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => 'resourcespace',
            'client_secret' => self::CLIENT_SECRET,
        ]);

        $second->assertOk();
        $this->assertNotSame($first['access_token'], $second->json('access_token'));

        // 旧 refresh token 已旋转作废
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => 'resourcespace',
            'client_secret' => self::CLIENT_SECRET,
        ])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }
}
