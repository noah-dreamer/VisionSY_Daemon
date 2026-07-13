<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '管理后台 · VisionSY')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-900" x-data="{ nav: false }">
    <div class="flex min-h-screen">
        {{-- 侧边栏（桌面） / 抽屉（移动） --}}
        <aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full transform flex-col bg-ink-950 transition-transform duration-200 lg:static lg:translate-x-0"
               :class="nav ? 'translate-x-0' : ''">
            <div class="flex h-16 items-center gap-2.5 border-b border-white/10 px-5">
                <span class="brand-mark h-9 w-9 text-sm" aria-hidden="true">V</span>
                <div class="leading-none">
                    <p class="text-sm font-semibold text-white">VisionSY</p>
                    <p class="mt-1 text-[11px] uppercase tracking-[0.12em] text-white/45">管理后台</p>
                </div>
            </div>

            @php
                $navItems = [
                    ['route' => 'admin.dashboard',           'label' => '概览',          'match' => 'admin.dashboard',      'icon' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 8.25V6Zm0 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25Zm9.75-9.75A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Zm0 9.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z'],
                    ['route' => 'admin.users.index',         'label' => '用户管理',      'match' => 'admin.users.*',        'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
                    ['route' => 'admin.campus-ips.index',    'label' => '校园出口 IP',   'match' => 'admin.campus-ips.*',   'icon' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0 0a8.95 8.95 0 0 0 4.5-1.2M3.6 9h16.8M3.6 15h16.8M12 3a13.4 13.4 0 0 1 0 18 13.4 13.4 0 0 1 0-18Z'],
                    ['route' => 'admin.oauth-clients.index', 'label' => 'OAuth 客户端',  'match' => 'admin.oauth-clients.*','icon' => 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z'],
                    ['route' => 'admin.audit-logs.index',    'label' => '审计日志',      'match' => 'admin.audit-logs.*',   'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
                ];
            @endphp

            <nav class="flex-1 space-y-1 px-3 py-4" aria-label="后台导航">
                @foreach ($navItems as $item)
                    @php $isCurrent = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @if($isCurrent) aria-current="page" @endif
                       class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition
                              {{ $isCurrent
                                  ? 'bg-brand-600 text-white shadow-glow'
                                  : 'text-white/65 hover:bg-white/5 hover:text-white' }}">
                        <svg class="h-5 w-5 shrink-0 {{ $isCurrent ? 'text-white' : 'text-white/50' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-white/10 p-4">
                <p class="truncate text-xs text-white/45">{{ auth()->user()->email }}</p>
                <div class="mt-3 flex items-center gap-2">
                    <a href="{{ route('dashboard') }}" class="flex-1 rounded-lg border border-white/15 px-3 py-1.5 text-center text-xs text-white/80 transition hover:bg-white/10">返回前台</a>
                    <form method="POST" action="{{ route('logout') }}" class="flex-1">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-white/15 px-3 py-1.5 text-xs text-white/80 transition hover:bg-white/10">退出</button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- 移动端遮罩 --}}
        <div class="fixed inset-0 z-40 bg-ink-950/50 lg:hidden" x-show="nav" x-transition.opacity @click="nav = false" x-cloak></div>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-ink-100 bg-white/85 px-4 backdrop-blur-xl sm:px-6">
                <div class="flex items-center gap-3">
                    <button type="button" class="-ml-2 inline-flex h-10 w-10 items-center justify-center rounded-lg text-ink-600 lg:hidden" @click="nav = true" aria-label="打开导航">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h1 class="text-base font-semibold tracking-tight text-ink-900">@yield('page-title', '管理后台')</h1>
                </div>
                <x-badge tone="blue" dot>system_admin</x-badge>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-6xl space-y-6">
                    @if (session('status'))
                        <div class="alert-success break-all" role="status">
                            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            <span>{{ session('status') }}</span>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="alert-error" role="alert">
                            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                            <ul class="list-inside list-disc space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </div>
</body>
</html>
