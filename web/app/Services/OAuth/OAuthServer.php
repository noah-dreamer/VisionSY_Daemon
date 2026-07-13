<?php

namespace App\Services\OAuth;

use App\Models\OAuthAccessToken;
use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthClient;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;

/**
 * 轻量 OAuth2 Provider（Authorization Code Flow）。
 *
 * 刻意不引入 Passport / league/oauth2-server：
 * 仅一个客户端（ResourceSpace），自写实现更少魔法、更易测试、依赖为零。
 * 授权码 / 访问令牌 / 刷新令牌均只存 sha256 hash。
 */
class OAuthServer
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function findClient(string $clientId): ?OAuthClient
    {
        return OAuthClient::query()->where('client_id', $clientId)->first();
    }

    /** 颁发授权码（明文只返回一次）。 */
    public function issueAuthorizationCode(OAuthClient $client, User $user, string $redirectUri, ?string $scope): string
    {
        $plain = Str::random(64);

        OAuthAuthorizationCode::create([
            'code_hash' => hash('sha256', $plain),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'expires_at' => now()->addSeconds((int) config('visionsy.oauth.code_ttl_seconds', 300)),
        ]);

        $this->audit->log(
            action: 'oauth.code_issued',
            actor: $user,
            targetType: 'oauth_client',
            targetId: $client->client_id,
            context: ['scope' => $scope],
        );

        return $plain;
    }

    /**
     * 授权码换 token。
     *
     * @return array{ok:bool,error:?string,payload:?array}
     */
    public function exchangeAuthorizationCode(OAuthClient $client, string $plainCode, string $redirectUri): array
    {
        $code = OAuthAuthorizationCode::query()
            ->where('code_hash', hash('sha256', $plainCode))
            ->first();

        if ($code === null
            || $code->client_id !== $client->id
            || $code->redirect_uri !== $redirectUri
            || $code->expires_at->isPast()
            || $code->used_at !== null
        ) {
            $this->audit->log(
                action: 'oauth.token_exchange_failed',
                targetType: 'oauth_client',
                targetId: $client->client_id,
                context: ['error' => 'invalid_grant'],
            );

            return ['ok' => false, 'error' => 'invalid_grant', 'payload' => null];
        }

        $user = $code->user;

        if ($user === null || ! $user->canEnterResourceSpace()) {
            return ['ok' => false, 'error' => 'access_denied', 'payload' => null];
        }

        $code->forceFill(['used_at' => now()])->save();

        return ['ok' => true, 'error' => null, 'payload' => $this->issueTokens($client, $user, $code->scope)];
    }

    /**
     * 刷新令牌换新 token。
     *
     * @return array{ok:bool,error:?string,payload:?array}
     */
    public function exchangeRefreshToken(OAuthClient $client, string $plainRefreshToken): array
    {
        $refresh = OAuthRefreshToken::query()
            ->where('token_hash', hash('sha256', $plainRefreshToken))
            ->first();

        if ($refresh === null || $refresh->client_id !== $client->id || ! $refresh->isUsable()) {
            return ['ok' => false, 'error' => 'invalid_grant', 'payload' => null];
        }

        $user = User::find($refresh->user_id);
        if ($user === null || ! $user->canEnterResourceSpace()) {
            return ['ok' => false, 'error' => 'access_denied', 'payload' => null];
        }

        // 旋转：旧 refresh token 作废，连带其 access token
        $refresh->forceFill(['revoked_at' => now()])->save();
        OAuthAccessToken::query()->whereKey($refresh->access_token_id)->update(['revoked_at' => now()]);

        return ['ok' => true, 'error' => null, 'payload' => $this->issueTokens($client, $user, $refresh->scope)];
    }

    /** Bearer token -> 用户。无效返回 null。 */
    public function resolveAccessToken(string $plainToken): ?OAuthAccessToken
    {
        $token = OAuthAccessToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        return ($token !== null && $token->isUsable()) ? $token : null;
    }

    /** userinfo 响应体（每次返回最新 role/groups，便于 RS 同步用户组）。 */
    public function userInfoPayload(User $user): array
    {
        return [
            'sub' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->display_name,
            'real_name' => $user->real_name,
            'role' => $user->role->value,
            'groups' => $user->resourceSpaceGroups(),
            'status' => $user->status->value,
            'real_name_required' => $user->role->requiresRealName() && empty($user->real_name),
        ];
    }

    private function issueTokens(OAuthClient $client, User $user, ?string $scope): array
    {
        $accessPlain = Str::random(64);
        $refreshPlain = Str::random(64);

        $accessTtl = (int) config('visionsy.oauth.access_token_ttl_seconds', 3600);
        $refreshTtl = (int) config('visionsy.oauth.refresh_token_ttl_seconds', 2592000);

        $access = OAuthAccessToken::create([
            'token_hash' => hash('sha256', $accessPlain),
            'client_id' => $client->id,
            'user_id' => $user->id,
            'scope' => $scope,
            'expires_at' => now()->addSeconds($accessTtl),
        ]);

        OAuthRefreshToken::create([
            'token_hash' => hash('sha256', $refreshPlain),
            'access_token_id' => $access->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'scope' => $scope,
            'expires_at' => now()->addSeconds($refreshTtl),
        ]);

        $this->audit->log(
            action: 'oauth.token_issued',
            actor: $user,
            targetType: 'oauth_client',
            targetId: $client->client_id,
            context: ['scope' => $scope],
        );

        return [
            'token_type' => 'Bearer',
            'access_token' => $accessPlain,
            'expires_in' => $accessTtl,
            'refresh_token' => $refreshPlain,
            'scope' => $scope ?? '',
        ];
    }
}
