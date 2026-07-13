@extends('layouts.admin')

@section('title', '用户组 · 管理后台')
@section('page-title', '用户组')

@section('content')
    <div class="flex items-center justify-between">
        <p class="prose-muted">
            媒体库权限组（对齐 ResourceSpace 用户组）。组内用户的媒体权限以组的权限位为准；未入组用户按其角色的默认矩阵。
        </p>
        <a href="{{ route('admin.user-groups.create') }}" class="btn-primary btn-sm shrink-0">新建用户组</a>
    </div>

    <div class="card mt-5 overflow-x-auto">
        <table class="min-w-full divide-y divide-ink-100">
            <thead>
                <tr>
                    <th class="table-head">组名</th>
                    <th class="table-head">权限</th>
                    <th class="table-head">高级限制</th>
                    <th class="table-head">成员</th>
                    <th class="table-head text-right">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($groups as $group)
                    <tr>
                        <td class="table-cell">
                            <div class="font-medium text-ink-900">{{ $group->name }}
                                @if ($group->is_registration_default)
                                    <x-badge tone="blue" class="ml-1.5">注册默认</x-badge>
                                @endif
                            </div>
                            @if ($group->description)
                                <div class="mt-0.5 text-xs text-ink-500">{{ $group->description }}</div>
                            @endif
                        </td>
                        <td class="table-cell">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach (\App\Models\UserGroup::permissionFlags() as $flag => [$label, $hint])
                                    @if ($group->{$flag})
                                        <x-badge tone="gray">{{ $label }}</x-badge>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="table-cell text-sm text-ink-600">
                            {{ $group->download_limit_per_day > 0 ? "每日 {$group->download_limit_per_day} 次下载" : '下载不限' }}
                            @if ($group->ip_restriction)
                                <div class="text-xs text-ink-400">IP：{{ $group->ip_restriction }}</div>
                            @endif
                        </td>
                        <td class="table-cell text-sm">{{ $group->users_count }}</td>
                        <td class="table-cell text-right">
                            <a href="{{ route('admin.user-groups.edit', $group) }}" class="btn-ghost btn-sm">编辑</a>
                            <form method="POST" action="{{ route('admin.user-groups.destroy', $group) }}" class="inline"
                                  onsubmit="return confirm('删除用户组「{{ $group->name }}」？组内 {{ $group->users_count }} 名成员将回退为角色默认权限。')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-ghost btn-sm text-red-600 hover:bg-red-50">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="table-cell text-center text-ink-400">还没有用户组。未入组用户按角色默认权限访问媒体库。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
