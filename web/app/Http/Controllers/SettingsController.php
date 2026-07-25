<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use App\Models\User;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.index', [
            'user' => $request->user(),
        ]);
    }

    public function password(Request $request): View
    {
        return view('settings.password', [
            'user' => $request->user(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50', Rule::unique('users', 'display_name')->ignore($user->id)],
        ], [
            'display_name.unique' => '该名称已被占用，请换一个再试。',
        ], [
            'display_name' => '显示名',
        ]);

        $user->update($validated);

        return redirect()->route('settings.index')->with('status', '账户名称已更新。');
    }

    public function checkName(Request $request): JsonResponse
    {
        $name = trim($request->query('name', ''));
        $user = $request->user();

        if ($name === '' || $name === $user->display_name) {
            return response()->json(['available' => true, 'suggestions' => []]);
        }

        $exists = User::where('display_name', $name)->where('id', '!=', $user->id)->exists();

        if (! $exists) {
            return response()->json(['available' => true, 'suggestions' => []]);
        }

        $suggestions = $this->generateSuggestions($name, $user->id);

        return response()->json(['available' => false, 'suggestions' => $suggestions]);
    }

    private function generateSuggestions(string $baseName, int $excludeUserId): array
    {
        $suggestions = [];
        $suffixes = [
            (string) random_int(100, 999),
            '_' . (string) random_int(100, 999),
            (string) now()->format('ymd'),
        ];

        foreach ($suffixes as $suffix) {
            $candidate = $baseName . $suffix;

            if (mb_strlen($candidate) > 50) {
                continue;
            }

            $exists = User::where('display_name', $candidate)->where('id', '!=', $excludeUserId)->exists();
            if (! $exists) {
                $suggestions[] = $candidate;
            }
        }

        $counter = 2;
        while (count($suggestions) < 3 && $counter <= 20) {
            $candidate = $baseName . $counter;
            if (mb_strlen($candidate) <= 50) {
                $exists = User::where('display_name', $candidate)->where('id', '!=', $excludeUserId)->exists();
                if (! $exists) {
                    $suggestions[] = $candidate;
                }
            }
            $counter++;
        }

        return $suggestions;
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp'],
        ], [], [
            'avatar' => '头像',
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        return redirect()->route('settings.index')->with('status', '头像已更新。');
    }

    public function updatePassword(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', function ($attribute, $value, $fail) use ($user) {
                if (! Hash::check($value, $user->getAuthPassword())) {
                    $fail('当前密码不正确。');
                }
            }],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], [
            'current_password' => '当前密码',
            'password' => '新密码',
        ]);

        $user->forceFill([
            'password_hash' => $validated['password'],
        ])->save();

        $audit->log('user.password_changed', $user, 'user', (string) $user->id);

        return redirect()->route('settings.password')->with('status', '密码已更新。');
    }
}
