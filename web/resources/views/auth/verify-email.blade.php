@extends('layouts.auth')

@section('title', '验证邮箱 · VisionSY')

@section('content')
    <div class="card p-8 text-center">
        <div class="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
        </div>

        <h1 class="text-xl font-semibold tracking-tight text-ink-950">请验证你的邮箱</h1>
        <p class="mt-2.5 prose-muted">
            我们已向 <span class="font-medium text-ink-900">{{ auth()->user()->email }}</span> 发送了一封验证邮件。
            点击邮件中的链接即可完成验证，之后在校园网环境登录一次即可激活账号。
        </p>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-7">
            @csrf
            <button type="submit" class="btn-secondary w-full">重新发送验证邮件</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-ghost w-full">退出登录</button>
        </form>
    </div>
@endsection
