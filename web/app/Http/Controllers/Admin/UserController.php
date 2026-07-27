<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGroup;
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
        $query = User::query()
            ->with('userGroup')
            ->latest('id');

        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('real_name', 'like', "%{$search}%");
            });
        }

        $validRoles = array_map(
            static fn (UserRole $role): string => $role->value,
            UserRole::cases(),
        );

        $validStatuses = array_map(
            static fn (UserStatus $status): string => $status->value,
            UserStatus::cases(),
        );

        $requestedRoles = (array) $request->query(
            'roles',
            $request->filled('role')
                ? [$request->query('role')]
                : [],
        );

        $requestedStatuses = (array) $request->query(
            'statuses',
            $request->filled('status')
                ? [$request->query('status')]
                : [],
        );

        $selectedRoles = array_values(array_unique(array_intersect(
            array_map(
                static fn ($value): string => (string) $value,
                array_filter($requestedRoles, 'is_scalar'),
            ),
            $validRoles,
        )));

        $selectedStatuses = array_values(array_unique(array_intersect(
            array_map(
                static fn ($value): string => (string) $value,
                array_filter($requestedStatuses, 'is_scalar'),
            ),
            $validStatuses,
        )));

        if ($selectedRoles !== []) {
            $query->whereIn('role', $selectedRoles);
        }

        if ($selectedStatuses !== []) {
            $query->whereIn('status', $selectedStatuses);
        }

        return view('admin.users.index', [
            'users' => $query->paginate(20)->withQueryString(),
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
            'filters' => [
                'q' => $search,
                'roles' => $selectedRoles,
                'statuses' => $selectedStatuses,
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => UserRole::adminAssignable(),
            'groups' => UserGroup::orderBy('name')->get(),
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
            'user_group_id' => ['nullable', 'integer', Rule::exists('user_groups', 'id')],
        ], [
            'email.unique' => '该邮箱已被使用。',
        ], [
            'display_name' => '显示名', 'real_name' => '真实姓名',
            'email' => '邮箱', 'role' => '角色', 'password' => '初始密码',
            'user_group_id' => '用户组',
        ]);

        $user = User::create([
            'display_name' => $validated['display_name'],
            'real_name' => $validated['real_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'user_group_id' => $validated['user_group_id'] ?? null,
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
            'groups' => UserGroup::orderBy('name')->get(),
        ]);
    }

    /** 修改角色 / 实名 / 显示名。 */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:50'],
            'real_name' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(array_map(fn ($r) => $r->value, UserRole::adminAssignable()))],
            'user_group_id' => ['nullable', 'integer', Rule::exists('user_groups', 'id')],
        ], [], ['display_name' => '显示名', 'real_name' => '真实姓名', 'role' => '角色', 'user_group_id' => '用户组']);

        $validated['user_group_id'] = $validated['user_group_id'] ?? null;

        $before = ['role' => $user->role->value, 'real_name' => $user->real_name, 'user_group_id' => $user->user_group_id];

        $user->forceFill($validated)->save();

        $this->audit->log('admin.user_updated', $request->user(), 'user', (string) $user->id, [
            'before' => $before,
            'after' => ['role' => $user->role->value, 'real_name' => $user->real_name, 'user_group_id' => $user->user_group_id],
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

    /** 删除账号。不能删除自己，也不能删除最后一名系统管理员。 */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['status' => '不能删除自己的账号。']);
        }

        if ($user->role === UserRole::SystemAdmin
            && User::where('role', UserRole::SystemAdmin)->where('id', '!=', $user->id)->doesntExist()) {
            return back()->withErrors(['status' => '至少需要保留一名系统管理员，无法删除该账号。']);
        }

        $email = $user->email;
        $user->delete();

        $this->audit->log('admin.user_deleted', $request->user(), 'user', (string) $user->id, [
            'email' => $email,
        ]);

        return redirect()->route('admin.users.index')->with('status', "账号 {$email} 已删除。");
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
