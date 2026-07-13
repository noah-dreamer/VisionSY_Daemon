<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OAuthController extends Controller
{
    public function __construct(
        private OAuthServer $server,
        private AuditLogger $audit,
    ) {
    }

    /**
     * GET /oauth/authorize
     * 仅授权码模式。客户端为第一方（ResourceSpace），通过校验后直接颁发授权码并 302 回跳。
     */
    public function authorize(Request $request): Response
    {
        $clientId = (string) $request->query('client_id', '');
        $redirectUri = (string) $request->query('redirect_uri', '');
        $responseType = (string) $request->query('response_type', '');
        $scope = $request->query('scope');
        $state = $request->query('state');

        $client = $this->server->findClient($clientId);

        // client / redirect_uri 不合法时绝不回跳，直接展示错误页（防开放重定向）
        if ($client === null || ! $client->allowsRedirectUri($redirectUri)) {
            return response()->view('oauth.error', [
                'message' => '无效的客户端或回调地址。',
            ], 400);
        }

        if ($responseType !== 'code') {
            return redirect()->away($this->buildRedirect($redirectUri, [
                'error' => 'unsupported_response_type',
                'state' => $state,
            ]));
        }

        $user = $request->user();

        if (! $user->canEnterResourceSpace()) {
            $this->audit->log('oauth.authorize_denied', $user, 'oauth_client', $client->client_id, [
                'reason' => 'user_status_'.$user->status->value,
            ]);

            return response()->view('oauth.error', [
                'message' => match (true) {
                    $user->isDisabled() => '账号已被禁用，无法进入媒体库。',
                    default => '账号尚未激活：请连接校园网后登录以激活账号。',
                },
            ], 403);
        }

        $code = $this->server->issueAuthorizationCode($client, $user, $redirectUri, $scope);

        return redirect()->away($this->buildRedirect($redirectUri, [
            'code' => $code,
            'state' => $state,
        ]));
    }

    /**
     * POST /oauth/token
     * 支持 authorization_code 与 refresh_token。客户端凭据走 POST 体或 HTTP Basic。
     */
    public function token(Request $request): JsonResponse
    {
        [$clientId, $clientSecret] = $this->clientCredentials($request);

        $client = $clientId !== '' ? $this->server->findClient($clientId) : null;

        if ($client === null || $clientSecret === '' || ! $client->verifySecret($clientSecret)) {
            $this->audit->log('oauth.client_auth_failed', null, 'oauth_client', $clientId ?: null);

            return response()->json(['error' => 'invalid_client'], 401);
        }

        $grantType = (string) $request->input('grant_type', '');

        if ($grantType === 'authorization_code') {
            $result = $this->server->exchangeAuthorizationCode(
                $client,
                (string) $request->input('code', ''),
                (string) $request->input('redirect_uri', ''),
            );
        } elseif ($grantType === 'refresh_token') {
            $result = $this->server->exchangeRefreshToken(
                $client,
                (string) $request->input('refresh_token', ''),
            );
        } else {
            return response()->json(['error' => 'unsupported_grant_type'], 400);
        }

        if (! $result['ok']) {
            return response()->json(['error' => $result['error']], 400);
        }

        return response()->json($result['payload'])
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * GET /oauth/userinfo
     * Bearer token 换最新用户信息（含 role / groups，供 RS 每次登录同步用户组）。
     */
    public function userinfo(Request $request): JsonResponse
    {
        $bearer = (string) $request->bearerToken();

        $token = $bearer !== '' ? $this->server->resolveAccessToken($bearer) : null;

        if ($token === null) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $user = $token->user;

        if ($user === null || ! $user->canEnterResourceSpace()) {
            $this->audit->log('oauth.userinfo_denied', $user, 'user', $user ? (string) $user->id : null);

            return response()->json(['error' => 'access_denied'], 403);
        }

        $this->audit->log('oauth.userinfo_served', $user, 'user', (string) $user->id);

        return response()->json($this->server->userInfoPayload($user));
    }

    /** @return array{0:string,1:string} */
    private function clientCredentials(Request $request): array
    {
        if ($request->getUser() !== null) {
            return [(string) $request->getUser(), (string) $request->getPassword()];
        }

        return [
            (string) $request->input('client_id', ''),
            (string) $request->input('client_secret', ''),
        ];
    }

    private function buildRedirect(string $redirectUri, array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri.$separator.http_build_query($params);
    }
}
