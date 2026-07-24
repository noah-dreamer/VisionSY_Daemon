@extends('layouts.app')

@section('title', '控制台 · VisionSY')

@push('head')
    <meta name="intranet-probe-url" content="{{ $intranetProbeUrl }}">
@endpush

@section('content')
    <section class="shell flex flex-1 flex-col justify-center py-6 sm:py-10">
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
                            <a href="{{ $mediaLibraryUrl }}" class="btn-primary shrink-0">
                                进入媒体库
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12l-7.5 7.5M21 12H3"/></svg>
                            </a>
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

            @if ($user->isSystemAdmin())
                <div class="card flex flex-col items-start justify-between gap-4 p-6 sm:flex-row sm:items-center">
                    <div>
                        <h2 class="h-card">管理后台</h2>
                        <p class="mt-1 prose-muted">用户、审计日志与系统管理。</p>
                    </div>
                    <a href="{{ route('admin.dashboard') }}" class="btn-secondary shrink-0">进入后台</a>
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
