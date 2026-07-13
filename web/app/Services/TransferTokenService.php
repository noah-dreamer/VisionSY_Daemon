<?php

namespace App\Services;

use App\Models\TransferToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * 大文件 307 直连 token 服务。
 *
 * - token 明文仅在生成响应中返回一次，库中只存 sha256 hash。
 * - 默认 TTL 10 分钟（TRANSFER_TOKEN_TTL_SECONDS 可配）。
 * - 默认一次性使用（TRANSFER_TOKEN_ONE_TIME 可配）。
 * - 校验失败统一返回失败原因，调用方（反向代理网关 / Nginx auth_request）据此回 403。
 */
class TransferTokenService
{
    public const ACTION_UPLOAD = 'upload';
    public const ACTION_DOWNLOAD = 'download';

    public const FAIL_NOT_FOUND = 'token_not_found';
    public const FAIL_EXPIRED = 'token_expired';
    public const FAIL_USED = 'token_already_used';
    public const FAIL_HOST_MISMATCH = 'host_mismatch';
    public const FAIL_ACTION_MISMATCH = 'action_mismatch';
    public const FAIL_METHOD_MISMATCH = 'method_mismatch';
    public const FAIL_IP_MISMATCH = 'ip_mismatch';

    public function __construct(private AuditLogger $audit)
    {
    }

    /**
     * 生成传输 token。
     *
     * @return array{token:string,record:TransferToken,expires_at:string}
     */
    public function generate(
        ?User $user,
        string $action,
        string $allowedHost,
        string $originalMethod,
        ?string $resourceId = null,
        ?string $filePathHash = null,
        ?string $clientIp = null,
        ?int $ttlSeconds = null,
        ?bool $oneTime = null,
    ): array {
        $ttlSeconds ??= (int) config('visionsy.transfer_token.ttl_seconds', 600);
        $oneTime ??= (bool) config('visionsy.transfer_token.one_time', true);

        $plain = Str::random(64);
        $expiresAt = now()->addSeconds($ttlSeconds);

        $record = TransferToken::create([
            'token_hash' => hash('sha256', $plain),
            'user_id' => $user?->id,
            'resource_id' => $resourceId,
            'file_path_hash' => $filePathHash,
            'action' => $action,
            'original_method' => strtoupper($originalMethod),
            'allowed_host' => $allowedHost,
            'client_ip' => $clientIp,
            'one_time' => $oneTime,
            'nonce' => bin2hex(random_bytes(16)),
            'expires_at' => $expiresAt,
        ]);

        $this->audit->log(
            action: 'transfer_token.generated',
            actor: $user,
            targetType: 'transfer_token',
            targetId: (string) $record->id,
            context: [
                'action' => $action,
                'allowed_host' => $allowedHost,
                'resource_id' => $resourceId,
                'client_ip' => $clientIp,
                'expires_at' => $expiresAt->toIso8601String(),
                'one_time' => $oneTime,
            ],
        );

        return [
            'token' => $plain,
            'record' => $record,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * 校验 token；不消费。
     *
     * @return array{ok:bool,reason:?string,record:?TransferToken}
     */
    public function validate(
        string $plainToken,
        ?string $host = null,
        ?string $action = null,
        ?string $clientIp = null,
        ?string $method = null,
    ): array {
        $record = TransferToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        $fail = function (string $reason) use ($record, $host, $action, $clientIp, $method): array {
            $this->audit->log(
                action: 'transfer_token.validation_failed',
                targetType: 'transfer_token',
                targetId: $record ? (string) $record->id : null,
                context: array_filter([
                    'reason' => $reason,
                    'host' => $host,
                    'action' => $action,
                    'client_ip' => $clientIp,
                    'method' => $method,
                ]),
            );

            return ['ok' => false, 'reason' => $reason, 'record' => $record];
        };

        if ($record === null) {
            return $fail(self::FAIL_NOT_FOUND);
        }
        if ($record->expires_at->isPast()) {
            return $fail(self::FAIL_EXPIRED);
        }
        if ($record->one_time && $record->used_at !== null) {
            return $fail(self::FAIL_USED);
        }
        if ($host !== null && ! hash_equals($record->allowed_host, $host)) {
            return $fail(self::FAIL_HOST_MISMATCH);
        }
        if ($action !== null && $record->action !== $action) {
            return $fail(self::FAIL_ACTION_MISMATCH);
        }
        if ($method !== null && strtoupper($method) !== $record->original_method) {
            return $fail(self::FAIL_METHOD_MISMATCH);
        }
        if ($record->client_ip !== null && $clientIp !== null && $record->client_ip !== $clientIp) {
            return $fail(self::FAIL_IP_MISMATCH);
        }

        return ['ok' => true, 'reason' => null, 'record' => $record];
    }

    /**
     * 校验并消费（标记 used_at）。
     *
     * @return array{ok:bool,reason:?string,record:?TransferToken}
     */
    public function consume(
        string $plainToken,
        ?string $host = null,
        ?string $action = null,
        ?string $clientIp = null,
        ?string $method = null,
    ): array {
        $result = $this->validate($plainToken, $host, $action, $clientIp, $method);

        if (! $result['ok']) {
            return $result;
        }

        /** @var TransferToken $record */
        $record = $result['record'];
        $record->forceFill(['used_at' => now()])->save();

        $this->audit->log(
            action: 'transfer_token.consumed',
            targetType: 'transfer_token',
            targetId: (string) $record->id,
            context: ['host' => $host, 'action' => $action, 'client_ip' => $clientIp],
        );

        return $result;
    }
}
