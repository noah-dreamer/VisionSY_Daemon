<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RedirectDecisionService;
use App\Services\TransferTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 大文件 307 直连 token API。
 * 所有端点经 internal_api 中间件（X-Internal-Secret）鉴权，
 * 供 ResourceSpace（生成）与 反向代理网关 / Nginx auth_request（校验/消费）调用。
 */
class TransferTokenController extends Controller
{
    public function __construct(
        private TransferTokenService $tokens,
        private RedirectDecisionService $redirects,
    ) {
    }

    /** POST /api/transfer-tokens —— 生成 token，并附带 307 目标判定。 */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['required', Rule::in([TransferTokenService::ACTION_UPLOAD, TransferTokenService::ACTION_DOWNLOAD])],
            'original_method' => ['required', Rule::in(['GET', 'POST', 'get', 'post'])],
            'resource_id' => ['nullable', 'string', 'max:120'],
            'file_path_hash' => ['nullable', 'string', 'max:64'],
            'client_ip' => ['required', 'ip'],
            'ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'one_time' => ['nullable', 'boolean'],
        ]);

        // 按真实客户端 IP 判定直连目标（校内 -> lan-ssy，校外 -> ssy）
        $decision = $this->redirects->decide($validated['client_ip']);

        $user = isset($validated['user_id']) ? User::find($validated['user_id']) : null;

        $issued = $this->tokens->generate(
            user: $user,
            action: $validated['action'],
            allowedHost: $decision['target_host'],
            originalMethod: $validated['original_method'],
            resourceId: $validated['resource_id'] ?? null,
            filePathHash: $validated['file_path_hash'] ?? null,
            clientIp: $validated['client_ip'],
            ttlSeconds: $validated['ttl_seconds'] ?? null,
            oneTime: array_key_exists('one_time', $validated) ? (bool) $validated['one_time'] : null,
        );

        return response()->json([
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'target_host' => $decision['target_host'],
            'target_base_url' => $decision['target_base_url'],
            'reason' => $decision['reason'],
        ], 201);
    }

    /** GET /api/transfer-tokens/validate —— 校验但不消费；失败统一 403。 */
    public function validateToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'host' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:16'],
            'client_ip' => ['nullable', 'ip'],
            'method' => ['nullable', 'string', 'max:10'],
        ]);

        $result = $this->tokens->validate(
            $validated['token'],
            $validated['host'] ?? null,
            $validated['action'] ?? null,
            $validated['client_ip'] ?? null,
            $validated['method'] ?? null,
        );

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'reason' => $result['reason']], 403);
        }

        return response()->json([
            'ok' => true,
            'action' => $result['record']->action,
            'allowed_host' => $result['record']->allowed_host,
            'user_id' => $result['record']->user_id,
            'expires_at' => $result['record']->expires_at->toIso8601String(),
        ]);
    }

    /** POST /api/transfer-tokens/consume —— 校验并标记 used_at；失败统一 403。 */
    public function consume(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'host' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:16'],
            'client_ip' => ['nullable', 'ip'],
            'method' => ['nullable', 'string', 'max:10'],
        ]);

        $result = $this->tokens->consume(
            $validated['token'],
            $validated['host'] ?? null,
            $validated['action'] ?? null,
            $validated['client_ip'] ?? null,
            $validated['method'] ?? null,
        );

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'reason' => $result['reason']], 403);
        }

        return response()->json(['ok' => true, 'used_at' => $result['record']->used_at->toIso8601String()]);
    }

    /** GET /api/redirect-decision —— 仅按 IP 返回 307 目标。 */
    public function redirectDecision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_ip' => ['required', 'ip'],
        ]);

        return response()->json($this->redirects->decide($validated['client_ip']));
    }
}
