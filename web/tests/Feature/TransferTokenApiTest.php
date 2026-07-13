<?php

namespace Tests\Feature;

use App\Models\CampusIpRange;
use App\Models\TransferToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private array $authHeader = ['X-Internal-Secret' => 'test-only-internal-secret'];

    private function issueToken(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create();

        return $this->postJson('/api/transfer-tokens', array_merge([
            'user_id' => $user->id,
            'action' => 'download',
            'original_method' => 'GET',
            'resource_id' => 'rs-1001',
            'client_ip' => '203.0.113.10',
        ], $overrides), $this->authHeader);
    }

    public function test_api_requires_internal_secret(): void
    {
        $this->postJson('/api/transfer-tokens', [])->assertStatus(401);

        $this->postJson('/api/transfer-tokens', [], ['X-Internal-Secret' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_generate_token_for_external_ip_targets_ssy(): void
    {
        $response = $this->issueToken();

        $response->assertStatus(201)->assertJson([
            'target_host' => 'files.example.com',
            'target_base_url' => 'https://files.example.com:8443',
            'reason' => 'external_ip',
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // 库中只存 hash，不存明文
        $this->assertDatabaseHas('transfer_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('transfer_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer_token.generated']);
    }

    public function test_generate_token_for_campus_ip_targets_lan_ssy(): void
    {
        CampusIpRange::create(['cidr' => '10.20.0.0/16', 'enabled' => true]);

        $this->issueToken(['client_ip' => '10.20.5.5'])
            ->assertStatus(201)
            ->assertJson([
                'target_host' => 'files-internal.example.com',
                'target_base_url' => 'https://files-internal.example.com:8443',
                'reason' => 'campus_ip_matched',
            ]);
    }

    public function test_validate_token_success(): void
    {
        $token = $this->issueToken()->json('token');

        $this->getJson('/api/transfer-tokens/validate?'.http_build_query([
            'token' => $token,
            'host' => 'files.example.com',
            'action' => 'download',
            'client_ip' => '203.0.113.10',
            'method' => 'GET',
        ]), $this->authHeader)->assertOk()->assertJson(['ok' => true, 'allowed_host' => 'files.example.com']);
    }

    public function test_missing_token_returns_403(): void
    {
        $this->getJson('/api/transfer-tokens/validate?token=nonexistent', $this->authHeader)
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'reason' => 'token_not_found']);
    }

    public function test_expired_token_returns_403(): void
    {
        $token = $this->issueToken()->json('token');

        TransferToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/transfer-tokens/validate?token='.$token, $this->authHeader)
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'reason' => 'token_expired']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer_token.validation_failed']);
    }

    public function test_host_mismatch_returns_403(): void
    {
        $token = $this->issueToken()->json('token');

        $this->getJson('/api/transfer-tokens/validate?'.http_build_query([
            'token' => $token,
            'host' => 'files-internal.example.com',
        ]), $this->authHeader)->assertStatus(403)->assertJson(['reason' => 'host_mismatch']);
    }

    public function test_action_mismatch_returns_403(): void
    {
        $token = $this->issueToken()->json('token');

        $this->getJson('/api/transfer-tokens/validate?'.http_build_query([
            'token' => $token,
            'action' => 'upload',
        ]), $this->authHeader)->assertStatus(403)->assertJson(['reason' => 'action_mismatch']);
    }

    public function test_ip_mismatch_returns_403(): void
    {
        $token = $this->issueToken()->json('token');

        $this->getJson('/api/transfer-tokens/validate?'.http_build_query([
            'token' => $token,
            'client_ip' => '198.51.100.99',
        ]), $this->authHeader)->assertStatus(403)->assertJson(['reason' => 'ip_mismatch']);
    }

    public function test_method_mismatch_returns_403(): void
    {
        $token = $this->issueToken(['action' => 'upload', 'original_method' => 'POST'])->json('token');

        $this->getJson('/api/transfer-tokens/validate?'.http_build_query([
            'token' => $token,
            'method' => 'GET',
        ]), $this->authHeader)->assertStatus(403)->assertJson(['reason' => 'method_mismatch']);
    }

    public function test_one_time_token_cannot_be_consumed_twice(): void
    {
        $token = $this->issueToken()->json('token');

        $this->postJson('/api/transfer-tokens/consume', ['token' => $token], $this->authHeader)
            ->assertOk()->assertJson(['ok' => true]);

        $this->postJson('/api/transfer-tokens/consume', ['token' => $token], $this->authHeader)
            ->assertStatus(403)->assertJson(['reason' => 'token_already_used']);
    }

    public function test_reusable_token_can_be_consumed_multiple_times(): void
    {
        $token = $this->issueToken(['one_time' => false])->json('token');

        $this->postJson('/api/transfer-tokens/consume', ['token' => $token], $this->authHeader)->assertOk();
        $this->postJson('/api/transfer-tokens/consume', ['token' => $token], $this->authHeader)->assertOk();
    }

    public function test_redirect_decision_endpoint(): void
    {
        CampusIpRange::create(['cidr' => '10.20.0.0/16', 'enabled' => true]);

        $this->getJson('/api/redirect-decision?client_ip=10.20.0.9', $this->authHeader)
            ->assertOk()->assertJson(['target_host' => 'files-internal.example.com', 'reason' => 'campus_ip_matched']);

        $this->getJson('/api/redirect-decision?client_ip=8.8.8.8', $this->authHeader)
            ->assertOk()->assertJson(['target_host' => 'files.example.com', 'reason' => 'external_ip']);
    }
}
