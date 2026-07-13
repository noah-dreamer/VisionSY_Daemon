@extends('layouts.admin')

@section('title', '用户管理 · 管理后台')
@section('page-title', '用户管理')

@section('content')
    <div class="card p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
                <label class="relative flex-1 sm:max-w-xs">
                    <span class="sr-only">搜索用户</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z"/></svg>
                    <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-input pl-9" placeholder="搜索邮箱 / 显示名 / 实名">
                </label>
                <select name="role" class="form-input sm:w-44" aria-label="按角色筛选">
                    <option value="">全部角色</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-input sm:w-40" aria-label="按状态筛选">
                    <option value="">全部状态</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn-secondary shrink-0">筛选</button>
            </form>
            <a href="{{ route('admin.users.create') }}" class="btn-primary shrink-0">创建实名账号</a>
        </div>
    </div>

    {{-- 桌面端表格 --}}
    <div class="card hidden overflow-x-auto md:block">
        <table class="min-w-full divide-y divide-ink-100">
            <thead class="bg-ink-50/70">
                <tr>
                    <th class="table-head">用户</th>
                    <th class="table-head">角色</th>
                    <th class="table-head">状态</th>
                    <th class="table-head hidden lg:table-cell">激活 IP</th>
                    <th class="table-head hidden lg:table-cell">最近登录</th>
                    <th class="table-head text-right">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse ($users as $u)
                    <tr class="transition hover:bg-ink-50/60">
                        <td class="table-cell">
                            <p class="font-medium text-ink-900">{{ $u->display_name }}</p>
                            <p class="text-xs text-ink-500">{{ $u->email }}</p>
                            @if ($u->real_name)<p class="text-xs text-ink-400">实名：{{ $u->real_name }}</p>@endif
                        </td>
                        <td class="table-cell"><x-badge tone="blue">{{ $u->role->label() }}</x-badge></td>
                        <td class="table-cell"><x-status-badge :status="$u->status" /></td>
                        <td class="table-cell hidden font-mono text-xs lg:table-cell">{{ $u->activation_ip ?? '—' }}</td>
                        <td class="table-cell hidden text-xs tabular-nums lg:table-cell">{{ $u->last_login_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="table-cell text-right">
                            <a href="{{ route('admin.users.edit', $u) }}" class="btn-ghost btn-sm">编辑</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-ink-400">没有符合条件的用户</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 移动端卡片 --}}
    <div class="space-y-3 md:hidden">
        @forelse ($users as $u)
            <div class="card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-ink-900">{{ $u->display_name }}</p>
                        <p class="truncate text-xs text-ink-500">{{ $u->email }}</p>
                    </div>
                    <x-status-badge :status="$u->status" />
                </div>
                <div class="mt-3 flex items-center justify-between">
                    <x-badge tone="blue">{{ $u->role->label() }}</x-badge>
                    <a href="{{ route('admin.users.edit', $u) }}" class="btn-ghost btn-sm">编辑</a>
                </div>
            </div>
        @empty
            <div class="card px-6 py-12 text-center text-sm text-ink-400">没有符合条件的用户</div>
        @endforelse
    </div>

    <div>{{ $users->links() }}</div>
@endsection
