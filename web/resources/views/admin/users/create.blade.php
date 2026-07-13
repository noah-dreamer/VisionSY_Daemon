@extends('layouts.admin')

@section('title', '创建实名账号 · 管理后台')
@section('page-title', '创建实名账号')

@section('content')
    <div class="card max-w-2xl p-7">
        <div class="alert-info mb-6">
            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
            <p>实名账号（宣传部成员 / 官微编辑老师 / 系统管理员）由管理员审核创建，创建后即为<span class="font-medium">已激活</span>状态，可直接通过 SSO 进入媒体库。</p>
        </div>

        <form method="POST" action="{{ route('admin.users.store') }}" class="grid gap-5 sm:grid-cols-2">
            @csrf
            <x-field name="display_name" label="显示名" :value="old('display_name')" required :maxlength="50" />
            <x-field name="real_name" label="真实姓名" :value="old('real_name')" required :maxlength="50" />
            <x-field name="email" label="邮箱" type="email" :value="old('email')" required class="sm:col-span-2" />

            <div>
                <label for="role" class="form-label">角色</label>
                <select id="role" name="role" class="form-input" required>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
                @error('role') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <x-field name="password" label="初始密码" :value="old('password')" mono required
                     placeholder="至少 8 位，请告知用户后让其修改" />

            <div class="flex items-center gap-3 sm:col-span-2">
                <button type="submit" class="btn-primary">创建账号</button>
                <a href="{{ route('admin.users.index') }}" class="btn-ghost">返回列表</a>
            </div>
        </form>
    </div>
@endsection
