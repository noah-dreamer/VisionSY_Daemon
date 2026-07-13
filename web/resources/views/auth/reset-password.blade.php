@extends('layouts.auth')

@section('title', '重置密码 · VisionSY')

@section('content')
    <div class="mb-8">
        <h1 class="h-page text-ink-950">设置新密码</h1>
        <p class="mt-2 prose-muted">请为账号设置一个新密码（至少 8 位）。</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-field name="email" label="邮箱" type="email" :value="old('email', $email)" required autofocus />
        <x-field name="password" label="新密码" type="password"
                 placeholder="至少 8 位" required autocomplete="new-password" />
        <x-field name="password_confirmation" label="确认新密码" type="password"
                 required autocomplete="new-password" />

        <button type="submit" class="btn-primary w-full">重置密码</button>
    </form>
@endsection
