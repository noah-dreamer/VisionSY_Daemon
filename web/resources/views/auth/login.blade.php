@extends('layouts.auth')

@section('title', '登录 · VisionSY')

@section('content')
    <div class="mb-8">
        <h1 class="h-page text-ink-950">登录 VisionSY</h1>
        <p class="mt-2 prose-muted">使用注册邮箱登录。待激活账号请在校园网环境登录以完成激活。</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="space-y-5"
          x-data="{ captchaEnabled: {{ config('visionsy.captcha.enabled') ? 'true' : 'false' }}, solved: false }"
          x-init="window.addEventListener('claw:verified', () => solved = true)">
        @csrf

        <x-field name="email" label="邮箱" type="email" :value="old('email')"
                 placeholder="name@example.edu.cn" required autofocus autocomplete="username" />

        <x-field name="password" label="密码" type="password"
                 placeholder="••••••••" required autocomplete="current-password">
            <x-slot:action>
                <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 transition hover:text-brand-700">忘记密码？</a>
            </x-slot:action>
        </x-field>

        @if (config('visionsy.captcha.enabled'))
            {{-- ============ PlayCaptcha 人机验证（github.com/mortspace/playcaptcha） ============
                 上游 React 组件 <ClawCaptcha>：抓娃娃机——把指定玩具抓起投入舱口即通过。
                 React 岛由 resources/js/claw-captcha.jsx 挂载到下方 root；
                 通关后服务端签发一次性 pass token 写入 #captcha_token 随表单提交。 --}}
            <div>
                <span class="form-label mb-1.5">人机验证</span>
                <div id="claw-captcha-root"
                     class="rounded-xl border border-ink-200 bg-white px-4 py-3"
                     style="--clawcap-bg:#ffffff; --clawcap-ink:#141721; --clawcap-muted:#8a90a3; --clawcap-accent:#3056d3; --clawcap-action:#ff5159;">
                </div>
                <input type="hidden" name="captcha_token" id="captcha_token" value="">
                @error('captcha')<p class="form-error">{{ $message }}</p>@enderror
                <p class="form-hint">用摇杆（或键盘方向键 + 空格）操作爪子，抓到指定玩具并投入舱口。</p>
            </div>
            @vite('resources/js/claw-captcha.jsx')
        @endif

        @error('email')<p class="form-error">{{ $message }}</p>@enderror

        <label class="flex items-center gap-2 text-sm text-ink-600">
            <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500">
            记住我
        </label>

        <button type="submit" class="btn-primary w-full"
                :disabled="captchaEnabled && !solved"
                :class="{ 'opacity-60': captchaEnabled && !solved }">登录</button>
    </form>

    <p class="mt-8 text-center text-sm text-ink-500">
        还没有账号？
        <a href="{{ route('register') }}" class="font-medium text-brand-600 transition hover:text-brand-700">立即注册</a>
    </p>
@endsection
