<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /** 验证邮件提示页。 */
    public function notice(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        return view('auth.verify-email');
    }

    /** 点击邮件中的签名链接完成验证。 */
    public function verify(EmailVerificationRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $request->fulfill(); // 写入 email_verified_at 并触发 Verified 事件

            if ($user->status === UserStatus::PendingEmailVerification) {
                $user->forceFill(['status' => UserStatus::PendingActivation])->save();
            }

            $audit->log('user.email_verified', $user, 'user', (string) $user->id);
        }

        return redirect()->route('dashboard')->with('status', '邮箱验证成功，请在校园网环境登录一次以激活账号。');
    }

    /** 重新发送验证邮件。 */
    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', '验证邮件已重新发送，请查收。');
    }
}
