<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserGroup;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 用户组管理。
 * 权限位 + 高级选项（每日下载限额、IP 限制、注册默认组）统一在官网后台维护。
 */
class UserGroupController extends Controller
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('admin.user-groups.index', [
            'groups' => UserGroup::withCount('users')->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.user-groups.create', [
            'flags' => UserGroup::permissionFlags(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = UserGroup::create($this->validated($request));

        if ($group->is_registration_default) {
            UserGroup::whereKeyNot($group->id)->update(['is_registration_default' => false]);
        }

        $this->audit->log('user_group.created', $request->user(), 'user_group', (string) $group->id, [
            'name' => $group->name,
        ]);

        return redirect()->route('admin.user-groups.index')
            ->with('status', "用户组「{$group->name}」已创建。");
    }

    public function edit(UserGroup $userGroup): View
    {
        return view('admin.user-groups.edit', [
            'group' => $userGroup,
            'flags' => UserGroup::permissionFlags(),
        ]);
    }

    public function update(Request $request, UserGroup $userGroup): RedirectResponse
    {
        $userGroup->update($this->validated($request, $userGroup->id));

        if ($userGroup->is_registration_default) {
            UserGroup::whereKeyNot($userGroup->id)->update(['is_registration_default' => false]);
        }

        $this->audit->log('user_group.updated', $request->user(), 'user_group', (string) $userGroup->id, [
            'name' => $userGroup->name,
        ]);

        return redirect()->route('admin.user-groups.index')
            ->with('status', "用户组「{$userGroup->name}」已更新。");
    }

    public function destroy(Request $request, UserGroup $userGroup): RedirectResponse
    {
        $name = $userGroup->name;
        $userGroup->delete(); // users.user_group_id 外键 nullOnDelete：成员自动回退角色矩阵

        $this->audit->log('user_group.deleted', $request->user(), 'user_group', (string) $userGroup->id, [
            'name' => $name,
        ]);

        return redirect()->route('admin.user-groups.index')
            ->with('status', "用户组「{$name}」已删除，原成员回退为角色默认权限。");
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('user_groups', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:255'],
            'download_limit_per_day' => ['required', 'integer', 'min:0', 'max:100000'],
            'ip_restriction' => ['nullable', 'string', 'max:255', 'regex:/^[0-9a-fA-F:.\/,\s]*$/'],
        ], [
            'name.required' => '请填写组名。',
            'name.unique' => '用户组名已存在，请换一个名称。',
            'name.max' => '组名不能超过 60 个字符。',
        ]);

        foreach (array_keys(UserGroup::permissionFlags()) as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['is_registration_default'] = $request->boolean('is_registration_default');

        return $data;
    }
}
