<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [], ['email' => '邮箱']);

        Password::sendResetLink($request->only('email'));

        // 不暴露邮箱是否存在
        return back()->with('status', '如果该邮箱已注册，重置链接已发送，请查收。');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [], ['email' => '邮箱', 'password' => '密码']);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) use ($audit) {
                $user->forceFill([
                    'password_hash' => $password, // hashed cast
                    'remember_token' => Str::random(60),
                ])->save();

                $audit->log('user.password_reset', $user, 'user', (string) $user->id);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', '密码已重置，请使用新密码登录。')
            : back()->withErrors(['email' => '重置链接无效或已过期。']);
    }
}
