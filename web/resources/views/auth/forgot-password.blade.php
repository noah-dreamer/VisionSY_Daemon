@extends('layouts.auth')

@section('title', '找回密码 · VisionSY')

@section('content')
    <div class="mb-8">
        <h1 class="h-page text-ink-950">找回密码</h1>
        <p class="mt-2 prose-muted">输入注册邮箱，我们会发送密码重置链接。实名管理账号请联系系统管理员重置。</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf
        <x-field name="email" label="邮箱" type="email" :value="old('email')"
                 placeholder="name@example.edu.cn" required autofocus />
        <button type="submit" class="btn-primary w-full">发送重置链接</button>
    </form>

    <p class="mt-8 text-center text-sm text-ink-500">
        <a href="{{ route('login') }}" class="font-medium text-brand-600 transition hover:text-brand-700">返回登录</a>
    </p>
@endsection
