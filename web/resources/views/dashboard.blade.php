@extends('layouts.app')

@section('title', '控制台 · VisionSY')

@section('content')
    <section class="shell py-12 sm:py-16">
        <div class="mx-auto max-w-4xl space-y-6">

            {{-- 顶部状态卡片 --}}
            <div class="card overflow-hidden">
                <div class="relative border-b border-ink-100 px-6 py-7 sm:px-8">
                    <div class="absolute inset-0 bg-gradient-to-r from-brand-50/70 via-white to-transparent" aria-hidden="true"></div>
                    <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-4">
                            <div class="brand-mark h-12 w-12 text-lg">{{ mb_substr($user->display_name, 0, 1) }}</div>
                            <div>
                                <h1 class="text-xl font-semibold tracking-tight text-ink-950">你好，{{ $user->display_name }}</h1>
                                <p class="mt-0.5 text-sm text-ink-500">{{ $user->email }}</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge tone="blue">{{ $user->role->label() }}</x-badge>
                            <x-status-badge :status="$user->status" />
                        </div>
                    </div>
                </div>

                <div class="space-y-6 px-6 py-7 sm:px-8">
                    @if ($user->status->value === 'pending_email_verification')
                        <div class="alert-info">
                            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/></svg>
                            <span>请先验证邮箱：我们已向 <span class="font-medium">{{ $user->email }}</span> 发送验证邮件。</span>
                        </div>
                        <a href="{{ route('verification.notice') }}" class="btn-primary">前往验证邮箱</a>

                    @elseif ($user->status->value === 'pending_activation')
                        <div class="alert-warning">
                            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                            <div>
                                <p class="font-medium">请连接校园网后登录以激活账号。</p>
                                <p class="mt-1 text-amber-800/80">激活只需在校园网环境登录官网一次。激活后可在任意网络环境使用媒体库。</p>
                            </div>
                        </div>

                        <dl class="grid gap-4 rounded-xl border border-ink-100 bg-ink-50/60 p-5 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">当前检测到的真实 IP</dt>
                                <dd class="mt-1 font-mono text-sm text-ink-900">{{ $clientIp ?? '未知' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">网络环境判定</dt>
                                <dd class="mt-1 flex items-center gap-2 text-sm {{ $isCampusIp ? 'text-emerald-600' : 'text-amber-600' }}">
                                    <span class="h-2 w-2 rounded-full {{ $isCampusIp ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                                    {{ $isCampusIp ? '校园网' : '非校园网' }}
                                </dd>
                            </div>
                        </dl>

                        <form method="POST" action="{{ route('dashboard.recheck') }}">
                            @csrf
                            <button type="submit" class="btn-primary">重新检测当前网络</button>
                        </form>

                    @elseif ($user->status->value === 'disabled')
                        <div class="alert-error">
                            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            <span>账号已被禁用，无法使用媒体库。如有疑问，请联系系统管理员。</span>
                        </div>

                    @else {{-- active --}}
                        <div class="flex flex-col items-start gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="h-card">账号已激活</h2>
                                <p class="mt-1 prose-muted">你可以免密进入 ResourceSpace 媒体库，按角色权限检索、浏览与下载素材。</p>
                            </div>
                            <a href="{{ $resourceSpaceUrl }}" class="btn-primary shrink-0">
                                进入媒体库
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3"/></svg>
                            </a>
                        </div>

                        <dl class="grid gap-4 rounded-xl border border-ink-100 bg-ink-50/60 p-5 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">当前 IP</dt>
                                <dd class="mt-1 font-mono text-sm text-ink-900">{{ $clientIp ?? '未知' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">激活时间</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ $user->activated_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">上次登录</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ $user->last_login_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                            </div>
                        </dl>
                    @endif
                </div>
            </div>

            {{-- 服务卡片 --}}
            <div class="grid gap-4 sm:grid-cols-2">
                @php
                    $isActive = $user->status->value === 'active';
                @endphp
                <a href="{{ $isActive ? $resourceSpaceUrl : '#' }}"
                   class="card {{ $isActive ? 'card-hover' : 'pointer-events-none opacity-60' }} group flex items-start gap-4 p-6"
                   @if(!$isActive) aria-disabled="true" tabindex="-1" @endif>
                    <x-feature-icon path="M2.25 12.76V19.5a2.25 2.25 0 0 0 2.25 2.25h15a2.25 2.25 0 0 0 2.25-2.25v-6.74M21 8.25 12 13 3 8.25 12 3.5l9 4.75Z" class="group-hover:bg-brand-600 group-hover:text-white group-hover:ring-brand-600" />
                    <div>
                        <h3 class="h-card">ResourceSpace 媒体库</h3>
                        <p class="mt-1 prose-muted">{{ $isActive ? '免密进入，按权限检索与下载素材。' : '激活后即可进入。' }}</p>
                    </div>
                </a>

                <div class="card p-6">
                    <div class="flex items-start gap-4">
                        <x-feature-icon path="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0a8.95 8.95 0 0 0 4.5-1.2M3.6 9h16.8M3.6 15h16.8M12 3a13.4 13.4 0 0 1 0 18 13.4 13.4 0 0 1 0-18Z" />
                        <div class="min-w-0">
                            <h3 class="h-card">账号与网络状态</h3>
                            <p class="mt-1 prose-muted">当前 IP <span class="font-mono text-ink-700">{{ $clientIp ?? '未知' }}</span></p>
                            @if ($user->status->value === 'pending_activation')
                                <form method="POST" action="{{ route('dashboard.recheck') }}" class="mt-3">
                                    @csrf
                                    <button type="submit" class="btn-secondary btn-sm">重新检测校园网</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if ($user->isSystemAdmin())
                <div class="card flex flex-col items-start justify-between gap-4 p-6 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="h-card">管理后台</h2>
                        <p class="mt-1 prose-muted">用户、校园网段、OAuth 客户端与审计日志管理。</p>
                    </div>
                    <a href="{{ route('admin.dashboard') }}" class="btn-secondary shrink-0">进入后台</a>
                </div>
            @endif
        </div>
    </section>
@endsection
