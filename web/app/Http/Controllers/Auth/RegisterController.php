<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], [
            'display_name' => '显示名',
            'email' => '邮箱',
            'password' => '密码',
        ]);

        $user = User::create([
            'display_name' => $validated['display_name'],
            'email' => $validated['email'],
            'password_hash' => $validated['password'], // hashed cast 自动加密
            'role' => UserRole::NormalUser,
            'status' => UserStatus::PendingEmailVerification,
        ]);

        $user->sendEmailVerificationNotification();

        $audit->log('user.registered', $user, 'user', (string) $user->id);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
