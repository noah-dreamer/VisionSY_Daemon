<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\MailDeliveryException;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email'))),
            'display_name' => trim((string) $request->input('display_name'))]);
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50', 'unique:users,display_name'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::min(8), function ($attribute, $value, $fail) { if (strlen($value) > 72) { $fail('密码的 UTF-8 长度不能超过 72 字节。'); } }],
        ], [
            'email.unique' => '该邮箱已被注册。',
            'display_name.unique' => '该显示名已被使用，请换一个。',
        ], [
            'display_name' => '显示名',
            'email' => '邮箱',
            'password' => '密码',
        ]);

        try {
            $user = DB::transaction(function () use ($validated, $audit) {
                $user = User::create([
                    'display_name' => $validated['display_name'],
                    'email' => $validated['email'],
                    'password_hash' => $validated['password'],
                    'role' => UserRole::NormalUser,
                    'status' => UserStatus::PendingEmailVerification,
                    'user_group_id' => UserGroup::where('is_registration_default', true)->value('id'),
                ]);
                app(\App\Services\EmailVerificationCodeService::class)->issue($user);
                $audit->log('user.registered', $user, 'user', (string) $user->id);
                return $user;
            });
        } catch (MailDeliveryException $error) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['email' => $error->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
