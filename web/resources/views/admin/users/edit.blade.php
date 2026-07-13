@extends('layouts.admin')

@section('title', '编辑账号 · 管理后台')
@section('page-title', '编辑账号')

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card p-7 lg:col-span-2">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="h-card">{{ $managedUser->display_name }}</h2>
                    <p class="mt-0.5 text-sm text-ink-500">{{ $managedUser->email }}</p>
                </div>
                <x-status-badge :status="$managedUser->status" />
            </div>

            <form method="POST" action="{{ route('admin.users.update', $managedUser) }}" class="mt-7 grid gap-5 sm:grid-cols-2">
                @csrf
                @method('PUT')
                <x-field name="display_name" label="显示名" :value="old('display_name', $managedUser->display_name)" required :maxlength="50" />
                <x-field name="real_name" label="真实姓名" :value="old('real_name', $managedUser->real_name)" :maxlength="50" />

                <div class="sm:col-span-2">
                    <label for="role" class="form-label">角色</label>
                    <select id="role" name="role" class="form-input" required>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role', $managedUser->role->value) === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    <p class="form-hint">角色变更后，用户下次 SSO 登录媒体库时自动同步用户组。</p>
                    @error('role') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary">保存修改</button>
                </div>
            </form>
        </div>

        <div class="space-y-6">
            <div class="card p-6">
                <h3 class="text-sm font-semibold text-ink-900">账号状态</h3>
                <p class="mt-1.5 prose-muted">
                    {{ $managedUser->isDisabled() ? '该账号当前已禁用，无法登录与 SSO。' : '禁用后用户将无法登录官网与进入媒体库。' }}
                </p>
                <form method="POST" action="{{ route('admin.users.toggle', $managedUser) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="{{ $managedUser->isDisabled() ? 'btn-primary' : 'btn-danger' }} w-full">
                        {{ $managedUser->isDisabled() ? '启用账号' : '禁用账号' }}
                    </button>
                </form>
            </div>

            <div class="card p-6">
                <h3 class="text-sm font-semibold text-ink-900">重置密码</h3>
                <p class="mt-1.5 prose-muted">生成随机新密码并仅展示一次，请安全告知用户。</p>
                <form method="POST" action="{{ route('admin.users.reset-password', $managedUser) }}" class="mt-4"
                      x-data @submit="if (! confirm('确认重置该账号密码？')) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="btn-secondary w-full">重置密码</button>
                </form>
            </div>

            <div class="card p-6">
                <h3 class="text-sm font-semibold text-ink-900">激活信息</h3>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">激活时间</dt><dd class="text-ink-900">{{ $managedUser->activated_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">激活 IP</dt><dd class="font-mono text-xs text-ink-900">{{ $managedUser->activation_ip ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">最近登录</dt><dd class="text-ink-900">{{ $managedUser->last_login_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>
    </div>
@endsection
