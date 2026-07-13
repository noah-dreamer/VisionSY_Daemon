<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 校园 IP 激活：pending_activation 用户在校园网登录一次即激活为 active + normal_user。
 */
class AccountActivationService
{
    public function __construct(
        private TrustedProxyIpResolver $ipResolver,
        private CampusIpService $campusIpService,
        private AuditLogger $audit,
    ) {
    }

    /**
     * 在登录成功（或用户主动点击「重新检测」）时调用。
     * 返回 true 表示本次完成了激活。
     */
    public function attemptActivation(User $user, Request $request): bool
    {
        if ($user->status !== UserStatus::PendingActivation) {
            return false;
        }

        $ip = $this->ipResolver->resolve($request);
        $range = $this->campusIpService->matchedRange($ip);

        if ($range === null) {
            return false;
        }

        $user->forceFill([
            'status' => UserStatus::Active,
            'role' => $user->role ?? UserRole::NormalUser,
            'activated_at' => now(),
            'activation_ip' => $ip,
            'activation_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
        ])->save();

        $this->audit->log(
            action: 'user.activated_by_campus_ip',
            actor: $user,
            targetType: 'user',
            targetId: (string) $user->id,
            context: ['ip' => $ip, 'cidr' => $range->cidr],
            request: $request,
        );

        return true;
    }
}
