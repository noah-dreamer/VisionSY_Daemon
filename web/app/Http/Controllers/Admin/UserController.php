<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index(Request $request): View
    {
        $query = User::query()->latest('id');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('real_name', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('admin.users.index', [
            'users' => $query->paginate(20)->withQueryString(),
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
            'filters' => $request->only(['q', 'role', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => UserRole::adminAssignable(),
        ]);
    }

    /** 创建实名账号（宣传部成员 / 官微编辑老师 / 系统管理员）。 */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50'],
            'real_name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, UserRole::adminAssignable()))],
            'password' => ['required', Password::min(8)],
        ], [], [
            'display_name' => '显示名', 'real_name' => '真实姓名',
            'email' => '邮箱', 'role' => '角色', 'password' => '初始密码',
        ]);

        $user = User::create([
            'display_name' => $validated['display_name'],
            'real_name' => $validated['real_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'status' => UserStatus::Active,
            'password_hash' => $validated['password'],
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->audit->log('admin.user_created', $request->user(), 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role->value,
        ]);

        return redirect()->route('admin.users.index')->with('status', "实名账号 {$user->email} 已创建。");
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'managedUser' => $user,
            'roles' => UserRole::adminAssignable(),
        ]);
    }

    /** 修改角色 / 实名 / 显示名。 */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50'],
            'real_name' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, UserRole::adminAssignable()))],
        ], [], ['display_name' => '显示名', 'real_name' => '真实姓名', 'role' => '角色']);

        $before = ['role' => $user->role->value, 'real_name' => $user->real_name];

        $user->forceFill($validated)->save();

        $this->audit->log('admin.user_updated', $request->user(), 'user', (string) $user->id, [
            'before' => $before,
            'after' => ['role' => $user->role->value, 'real_name' => $user->real_name],
        ]);

        return redirect()->route('admin.users.edit', $user)->with('status', '账号信息已更新。');
    }

    /** 启用 / 禁用。 */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['status' => '不能禁用自己的账号。']);
        }

        $newStatus = $user->isDisabled() ? UserStatus::Active : UserStatus::Disabled;
        $user->forceFill(['status' => $newStatus])->save();

        $this->audit->log(
            $newStatus === UserStatus::Disabled ? 'admin.user_disabled' : 'admin.user_enabled',
            $request->user(),
            'user',
            (string) $user->id,
            ['email' => $user->email],
        );

        return back()->with('status', $newStatus === UserStatus::Disabled ? '账号已禁用。' : '账号已启用。');
    }

    /** 重置密码：生成随机密码一次性展示给管理员。 */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $newPassword = Str::password(16, symbols: false);

        $user->forceFill([
            'password_hash' => $newPassword,
            'remember_token' => Str::random(60),
        ])->save();

        $this->audit->log('admin.user_password_reset', $request->user(), 'user', (string) $user->id, [
            'email' => $user->email,
        ]);

        return back()->with('status', "密码已重置，请安全告知用户。新密码：{$newPassword}");
    }
}
