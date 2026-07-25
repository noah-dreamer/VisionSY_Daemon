@extends('layouts.app')

@section('title', '开始使用 · VisionSY')

@push('head')
    <meta name="intranet-probe-url" content="{{ $intranetProbeUrl }}">
@endpush

@section('content')
    <section class="shell flex flex-1 flex-col justify-center py-6 sm:py-10">
        <div class="mx-auto w-full max-w-6xl space-y-6">

            {{-- 顶部状态卡片 --}}
            <div class="card overflow-hidden">
                <div class="relative border-b border-ink-100 px-6 py-7 sm:px-8">
                    <div class="absolute inset-0 bg-gradient-to-r from-brand-50/70 via-white to-transparent" aria-hidden="true"></div>
                    <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-4">
                            @if ($user->avatar_path)
                                <img src="{{ Storage::disk('public')->url($user->avatar_path) }}" alt="头像" class="h-12 w-12 rounded-full object-cover">
                            @else
                                <div class="brand-mark h-12 w-12 text-lg">{{ mb_substr($user->display_name, 0, 1) }}</div>
                            @endif
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
                            <span>请先验证邮箱：我们已向 <span class="font-medium">{{ $user->email }}</span> 发送了 6 位数字验证码。</span>
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

                        <div class="rounded-xl border border-ink-100 bg-ink-50/60 p-5">
                            <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">连接方式</dt>
                            <dd id="network-status" class="mt-1 flex items-center gap-2 text-sm text-ink-400">
                                <span class="h-2 w-2 rounded-full bg-ink-300"></span>
                                检测中...
                            </dd>
                        </div>

                        <form id="recheck-form" method="POST" action="{{ route('dashboard.recheck') }}">
                            @csrf
                            <input type="hidden" name="is_campus" id="recheck-is-campus" value="0">
                            <button type="submit" id="recheck-btn" class="btn-primary" disabled>检测中...</button>
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
                                <p class="mt-1 prose-muted">你可以直接进入站内媒体库，按角色权限检索、浏览与下载素材。</p>
                            </div>
                        </div>

                        <dl class="grid gap-4 rounded-xl border border-ink-100 bg-ink-50/60 p-5 sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">连接方式</dt>
                                <dd id="network-status" class="mt-1 inline-flex items-center gap-1.5 rounded-full border border-ink-200 bg-ink-100 px-2.5 py-1 text-xs text-ink-600">
                                    <span class="h-2 w-2 rounded-full bg-ink-300"></span>
                                    检测中...
                                </dd>
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

            {{-- 已激活状态下的功能卡片 --}}
            @if ($user->status->value === 'active')
            <div class="flex flex-wrap gap-4">
                {{-- 跳转设置 --}}
                <div class="card flex flex-1 min-w-[280px] flex-col items-start justify-between gap-4 p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                        </div>
                        <div>
                            <h2 class="h-card">{{ $user->isSystemAdmin() ? '账户与系统设置' : '账户与设置' }}</h2>
                            <p class="mt-1 prose-muted">管理个人资料、头像、密码{{ $user->isSystemAdmin() ? '与系统配置。' : '。' }}</p>
                        </div>
                    </div>
                    <a href="{{ route('settings.index') }}" class="btn-secondary shrink-0">
                        进入设置
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3"/></svg>
                    </a>
                </div>

                {{-- 跳转媒体库 --}}
                <div class="card flex flex-1 min-w-[280px] flex-col items-start justify-between gap-4 p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0 0 21.75 19.5V4.5A1.5 1.5 0 0 0 20.25 3H3.75A1.5 1.5 0 0 0 2.25 4.5v15A1.5 1.5 0 0 0 3.75 21Z"/></svg>
                        </div>
                        <div>
                            <h2 class="h-card">进入媒体库</h2>
                            <p class="mt-1 prose-muted">检索、浏览与下载校园媒体素材。</p>
                        </div>
                    </div>
                    <a href="{{ $mediaLibraryUrl }}" class="btn-primary shrink-0">
                        进入媒体库
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3"/></svg>
                    </a>
                </div>

                {{-- 下载配额（非管理员用户专属） --}}
                @if (! $user->isSystemAdmin())
                <div class="card w-full p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                        </div>
                        <div>
                            <h2 class="h-card">下载配额</h2>
                            <p class="mt-1 prose-muted">今日下载次数统计。</p>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-4 rounded-xl border border-ink-100 bg-ink-50/60 p-5 sm:grid-cols-3">
                        @if ($downloadUnlimited)
                            <div class="sm:col-span-3">
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">状态</dt>
                                <dd class="mt-1 text-sm text-ink-900">
                                    @if ($user->userGroup)
                                        不限额
                                    @else
                                        按角色默认权限（未入组）
                                    @endif
                                </dd>
                            </div>
                        @else
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">今日已用</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ max(0, $downloadLimit - $downloadRemaining) }} 次</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">每日总额</dt>
                                <dd class="mt-1 text-sm text-ink-900">{{ $downloadLimit }} 次</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wider text-ink-400">剩余</dt>
                                <dd class="mt-1 text-sm font-semibold {{ $downloadRemaining === 0 ? 'text-red-600' : 'text-ink-900' }}">{{ $downloadRemaining }} 次</dd>
                            </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            @endif
        </div>
    </section>
@endsection

@push('scripts')
<script type="module">
import { probeIntranet } from '{{ Vite::asset('resources/js/intranet-probe.js') }}';

function renderConnectionType(el, isCampus) {
    const isCapsule = el.classList.contains('rounded-full');

    if (isCapsule) {
        el.classList.remove('text-ink-600', 'text-emerald-600', 'text-amber-600', 'border-ink-200', 'border-emerald-200', 'border-amber-200', 'bg-ink-100', 'bg-emerald-50', 'bg-amber-50');
        el.classList.add(
            isCampus ? 'text-emerald-600' : 'text-amber-600',
            isCampus ? 'border-emerald-200' : 'border-amber-200',
            isCampus ? 'bg-emerald-50' : 'bg-amber-50'
        );
    } else {
        el.classList.remove('text-ink-400', 'text-emerald-600', 'text-amber-600');
        el.classList.add(isCampus ? 'text-emerald-600' : 'text-amber-600');
    }

    const dot = el.querySelector('span');
    if (dot) {
        dot.className = 'h-2 w-2 rounded-full ' + (isCampus ? 'bg-emerald-500' : 'bg-amber-500');
    }

    const text = isCampus ? '直连服务器' : '中转连接服务器';
    for (const child of el.childNodes) {
        if (child.nodeType === Node.TEXT_NODE && child.textContent.trim()) {
            child.textContent = text;
            return;
        }
    }
}

(async () => {
    const isCampus = await probeIntranet();
    const statusEl = document.getElementById('network-status');
    const formEl = document.getElementById('recheck-form');
    const btnEl = document.getElementById('recheck-btn');
    const isCampusInput = document.getElementById('recheck-is-campus');

    if (isCampusInput) isCampusInput.value = isCampus ? '1' : '0';

    if (statusEl) {
        renderConnectionType(statusEl, isCampus);
    }

    if (btnEl) {
        btnEl.disabled = false;
        btnEl.textContent = '重新检测当前网络';
    }
    if (formEl) {
        formEl.addEventListener('submit', () => {
            if (isCampusInput) isCampusInput.value = isCampus ? '1' : '0';
        });
    }
})();
</script>
@endpush
