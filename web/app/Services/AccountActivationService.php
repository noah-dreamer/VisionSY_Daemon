<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 校园网激活：pending_activation 用户在校园网环境登录/检测后激活为 active + normal_user。
 * 校园网判断由前端完成（fetch 探测内网容器），后端仅根据传入的 isCampus 标志决定是否激活。
 */
class AccountActivationService
{
    public function __construct(
        private TrustedProxyIpResolver $ipResolver,
        private AuditLogger $audit,
    ) {
    }

    /**
     * 在前端完成内网探测后调用。返回 true 表示本次完成了激活。
     */
    public function attemptActivation(User $user, Request $request, bool $isCampus): bool
    {
        if ($user->status !== UserStatus::PendingActivation) {
            return false;
        }

        if (! $isCampus) {
            return false;
        }

        $ip = $this->ipResolver->resolve($request);

        $user->forceFill([
            'status' => UserStatus::Active,
            'role' => $user->role ?? UserRole::NormalUser,
            'activated_at' => now(),
            'activation_ip' => $ip,
            'activation_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ])->save();

        $this->audit->log(
            action: 'user.activated_by_intranet_probe',
            actor: $user,
            targetType: 'user',
            targetId: (string) $user->id,
            context: ['ip' => $ip],
            request: $request,
        );

        return true;
    }
}
