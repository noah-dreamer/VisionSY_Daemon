<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/** 统一审计日志写入。 */
class AuditLogger
{
    public function __construct(private TrustedProxyIpResolver $ipResolver)
    {
    }

    public function log(
        string $action,
        ?User $actor = null,
        ?string $targetType = null,
        ?string $targetId = null,
        array $context = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::create([
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip' => $request ? $this->ipResolver->resolve($request) : null,
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'context' => $context ?: null,
        ]);
    }
}
