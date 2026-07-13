@extends('layouts.auth')

@section('title', '注册 · VisionSY')

@section('content')
    <div class="mb-8">
        <h1 class="h-page text-ink-950">注册 VisionSY 账号</h1>
        <p class="mt-2 prose-muted">注册后请先验证邮箱，再在校园网环境登录一次完成激活。</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <x-field name="display_name" label="显示名" :value="old('display_name')"
                 placeholder="例如：张同学" required autofocus :maxlength="50" />

        <x-field name="email" label="邮箱" type="email" :value="old('email')"
                 placeholder="name@example.edu.cn" required autocomplete="username" />

        <x-field name="password" label="密码" type="password"
                 placeholder="至少 8 位" required autocomplete="new-password" />

        <x-field name="password_confirmation" label="确认密码" type="password"
                 placeholder="再次输入密码" required autocomplete="new-password" />

        <button type="submit" class="btn-primary w-full">创建账号</button>
    </form>

    <p class="mt-8 text-center text-sm text-ink-500">
        已有账号？
        <a href="{{ route('login') }}" class="font-medium text-brand-600 transition hover:text-brand-700">直接登录</a>
    </p>
@endsection
