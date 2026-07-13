<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountActivationService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        Request $request,
        AccountActivationService $activation,
        AuditLogger $audit,
    ): RedirectResponse {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [], ['email' => '邮箱', 'password' => '密码']);

        $throttleKey = mb_strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "登录尝试过于频繁，请 {$seconds} 秒后再试。",
            ]);
        }

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user !== null && $user->isDisabled()) {
            RateLimiter::hit($throttleKey, 60);
            $audit->log('user.login_blocked_disabled', null, 'user', (string) $user->id);

            throw ValidationException::withMessages([
                'email' => '该账号已被禁用，如有疑问请联系管理员。',
            ]);
        }

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $request->boolean('remember'),
        )) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => '邮箱或密码不正确。',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        $audit->log('user.logged_in', $user, 'user', (string) $user->id);

        // 登录即检查校园 IP，命中则激活账号
        $activation->attemptActivation($user, $request);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
