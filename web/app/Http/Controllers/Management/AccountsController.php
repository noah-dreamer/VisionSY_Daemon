<?php
namespace App\Http\Controllers\Management;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountsController extends Controller
{
    public function users(Request $request)
    {
        return User::with('userGroup')->when($request->query('q'), function ($q, $search) {
            $q->where(fn ($q) => $q->where('email', 'like', '%'.$search.'%')->orWhere('display_name', 'like', '%'.$search.'%'));
        })->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))->latest('id')->paginate(20);
    }
    public function saveUser(Request $request, ?User $user = null)
    {
        $data = $request->validate([
            'email' => [$user ? 'sometimes' : 'required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'display_name' => ['required', 'string', 'max:50', Rule::unique('users')->ignore($user?->id)],
            'real_name' => [Rule::requiredIf(in_array($request->input('role'), ['system_admin', 'propaganda_member', 'wechat_editor_teacher'], true)), 'nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(array_column(UserRole::adminAssignable(), 'value'))],
            'status' => ['required', Rule::in(array_column(UserStatus::cases(), 'value'))],
            'user_group_id' => ['nullable', 'integer', 'exists:user_groups,id'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:72', function ($attribute, $value, $fail) { if (strlen($value) > 72) { $fail('密码的 UTF-8 长度不能超过 72 字节。'); } }],
        ]);
        if ($user && $user->id === $request->user()->id) {
            abort_if($data['role'] !== 'system_admin' || $data['status'] !== 'active', 422, '不能停用或降低当前连接管理员的权限。');
        }
        $saved = DB::transaction(function () use ($data, $user) {
            // Lock the administrator set in a stable order before any destructive role change.
            $admins = User::where('role', 'system_admin')->orderBy('id')->lockForUpdate()->get();
            if ($user && $user->isSystemAdmin() && $user->isActive()
                && ($data['role'] !== 'system_admin' || $data['status'] !== 'active')) {
                abort_unless($admins->where('status', UserStatus::Active)->where('id', '!=', $user->id)->isNotEmpty(), 422, '至少保留一名已激活的系统管理员。');
            }
            $attributes = $data;
            unset($attributes['password']);
            if (! empty($data['password'])) {
                $attributes['password_hash'] = $data['password'];
                $attributes['remember_token'] = Str::random(60);
            }
            if ($user) {
                $user->forceFill($attributes)->save();
                return $user;
            }
            return User::create($attributes + ['email_verified_at' => now(), 'activated_at' => now()]);
        });
        app(AuditLogger::class)->log($user ? 'admin.user_updated' : 'admin.user_created', $request->user(), 'user', (string) $saved->id);
        return response()->json(['message' => '用户已保存。', 'data' => $saved->fresh('userGroup')]);
    }
    public function deleteUser(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, '不能删除当前连接管理员。');
        DB::transaction(function () use ($user) {
            $admins = User::where('role', 'system_admin')->orderBy('id')->lockForUpdate()->get();
            if ($user->isSystemAdmin() && $user->isActive()) {
                abort_unless($admins->where('status', UserStatus::Active)->where('id', '!=', $user->id)->isNotEmpty(), 422, '至少保留一名已激活的系统管理员。');
            }
            $user->delete();
        });
        app(AuditLogger::class)->log('admin.user_deleted', $request->user(), 'user', (string) $user->id);
        return response()->json(['message' => '用户已删除。']);
    }
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:72', function ($attribute, $value, $fail) { if (strlen($value) > 72) { $fail('密码的 UTF-8 长度不能超过 72 字节。'); } }]]);
        $user->forceFill(['password_hash' => $data['password'], 'remember_token' => Str::random(60)])->save();
        app(AuditLogger::class)->log('admin.user_password_reset', $request->user(), 'user', (string) $user->id);
        return response()->json(['message' => '密码已重置。']);
    }
    public function groups()
    {
        return response()->json(['data' => UserGroup::withCount('users')->orderBy('id')->get(), 'flags' => UserGroup::permissionFlags()]);
    }
    public function saveGroup(Request $request, ?UserGroup $group = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('user_groups')->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'download_limit_per_day' => ['required', 'integer', 'min:0', 'max:100000'],
            'ip_restriction' => ['nullable', 'string', 'max:255', 'regex:/^[0-9a-fA-F:.\/,\s]*$/'],
            'is_registration_default' => ['boolean'],
        ] + array_fill_keys(array_keys(UserGroup::permissionFlags()), ['boolean']));
        $saved = DB::transaction(function () use ($group, $data) {
            UserGroup::orderBy('id')->lockForUpdate()->get();
            $saved = $group ?? new UserGroup();
            $saved->fill($data)->save();
            if ($saved->is_registration_default) {
                UserGroup::whereKeyNot($saved->id)->update(['is_registration_default' => false]);
            }
            return $saved;
        }, 3);
        app(AuditLogger::class)->log('user_group.saved', $request->user(), 'user_group', (string) $saved->id);
        return response()->json(['message' => '用户组已保存。', 'data' => $saved]);
    }
    public function deleteGroup(Request $request, UserGroup $group)
    {
        $group->delete();
        app(AuditLogger::class)->log('user_group.deleted', $request->user(), 'user_group', (string) $group->id);
        return response()->json(['message' => '用户组已删除，成员回退为角色默认权限。']);
    }
}
