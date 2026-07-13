@extends('layouts.auth')

@section('title', '登录 · VisionSY')

@section('content')
    <div class="mb-8">
        <h1 class="h-page text-ink-950">登录 VisionSY</h1>
        <p class="mt-2 prose-muted">使用注册邮箱登录。待激活账号请在校园网环境登录以完成激活。</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <x-field name="email" label="邮箱" type="email" :value="old('email')"
                 placeholder="name@example.edu.cn" required autofocus autocomplete="username" />

        <x-field name="password" label="密码" type="password"
                 placeholder="••••••••" required autocomplete="current-password">
            <x-slot:action>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 transition hover:text-brand-700">忘记密码？</a>
            </x-slot:action>
        </x-field>

        <label class="flex items-center gap-2 text-sm text-ink-600">
            <input type="checkbox" name="remember" class="form-checkbox">
            记住我
        </label>

        <button type="submit" class="btn-primary w-full">登录</button>
    </form>

    <p class="mt-8 text-center text-sm text-ink-500">
        还没有账号？
        <a href="{{ route('register') }}" class="font-medium text-brand-600 transition hover:text-brand-700">邮箱注册</a>
    </p>
@endsection
