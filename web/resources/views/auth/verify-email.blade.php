@extends('layouts.auth')

@section('title', '验证邮箱 · VisionSY')

@section('content')
    <div class="card p-8 text-center"
         x-data="verifyCodeInput({{ (int) ($resendAvailableIn ?? 0) }})">
        <div class="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
        </div>

        <h1 class="text-xl font-semibold tracking-tight text-ink-950">输入邮箱验证码</h1>
        <p class="mt-2.5 prose-muted">
            我们已向 <span class="font-medium text-ink-900">{{ auth()->user()->email }}</span> 发送了一个
            <span class="font-medium text-ink-900">6 位数字验证码</span>（10 分钟内有效）。
        </p>

        <form method="POST" action="{{ route('verification.verify-code') }}" class="mt-7">
            @csrf
            <input
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="\d{6}"
                maxlength="6"
                required
                autofocus
                placeholder="······"
                x-model="code"
                @input="code = code.replace(/\D/g, '').slice(0, 6)"
                class="form-input py-4 text-center font-mono text-2xl font-semibold tracking-[0.6em]"
                aria-label="6 位数字验证码"
            >
            @error('code')<p class="form-error text-left">{{ $message }}</p>@enderror

            <button type="submit" class="btn-primary mt-5 w-full" :disabled="code.length !== 6">验证并继续</button>
        </form>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-secondary w-full" :disabled="cooldown > 0">
                <span x-show="cooldown === 0">重新发送验证码</span>
                <span x-show="cooldown > 0" x-cloak x-text="`重新发送（${cooldown}s）`"></span>
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-ghost w-full">退出登录</button>
        </form>

        <p class="mt-6 text-xs text-ink-400">
            收不到邮件？请检查垃圾箱，或确认邮箱地址无误后重新发送。验证成功后还需在校园网环境登录一次以激活账号。
        </p>
    </div>
@endsection
