<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\EmailVerificationCodeService;
use App\Services\MailConfiguration;
use App\Services\MailDeliveryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 邮箱验证（6 位数字验证码模式）。
 * 注册后系统向邮箱发送验证码，用户在验证页输入验证码完成验证。
 */
class EmailVerificationController extends Controller
{
    /** 验证码输入页。 */
    public function notice(Request $request, EmailVerificationCodeService $codes)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email', [
            'resendAvailableIn' => $codes->resendAvailableIn($request->user()),
            'mailConfigured' => app(MailConfiguration::class)->current()['enabled'],
        ]);
    }

    /** 提交验证码。 */
    public function verifyCode(
        Request $request,
        EmailVerificationCodeService $codes,
        AuditLogger $audit,
    ): RedirectResponse {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ], [], ['code' => '验证码']);

        $result = $codes->verify($user, $validated['code']);

        if ($result !== EmailVerificationCodeService::RESULT_OK) {
            return back()->withErrors(['code' => match ($result) {
                EmailVerificationCodeService::RESULT_EXPIRED => '验证码已过期，请重新发送。',
                EmailVerificationCodeService::RESULT_TOO_MANY_ATTEMPTS => '尝试次数过多，该验证码已失效，请重新发送。',
                EmailVerificationCodeService::RESULT_NOT_FOUND => '请先获取验证码。',
                default => '验证码不正确，请重新输入。',
            }]);
        }

        $user->markEmailAsVerified();

        if ($user->status === UserStatus::PendingEmailVerification) {
            $user->forceFill(['status' => UserStatus::PendingActivation])->save();
        }

        $audit->log('user.email_verified', $user, 'user', (string) $user->id);

        return redirect()->route('dashboard')
            ->with('status', '邮箱验证成功，请在校园网环境登录一次以激活账号。');
    }

    /** 重新发送验证码（60 秒冷却）。 */
    public function send(Request $request, EmailVerificationCodeService $codes): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        try {
            if (! $codes->issue($request->user())) {
                $wait = $codes->resendAvailableIn($request->user());
                return back()->withErrors(['code' => "发送过于频繁，请 {$wait} 秒后再试。"]);
            }
        } catch (MailDeliveryException $error) {
            return back()->withErrors(['code' => $error->getMessage()]);
        }

        return back()->with('status', '验证码已发送，请查收邮箱。');
    }
}
