@extends('layouts.app')

@section('no-footer', true)

@section('content')
<div class="shell max-w-[88rem] px-4 pb-12 pt-6 sm:px-6 sm:pt-8">
    <div class="settings-shell h-[calc(100vh-8.5rem)] sm:h-[calc(100vh-9rem)]">
        {{-- 桌面端侧边栏 --}}
        <aside class="hidden w-60 shrink-0 border-r border-ink-100 bg-ink-50/40 lg:block" aria-label="设置导航">
            <nav class="sticky-below-nav space-y-6 px-3 py-6">
                @foreach ($navItems as $group)
                    @if (($group['adminOnly'] ?? false) && ! auth()->user()->isSystemAdmin())
                        @continue
                    @endif

                    <div>
                        <h3 class="px-3 text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">
                            {{ $group['label'] }}
                        </h3>

                        <div class="mt-2 space-y-0.5">
                            @foreach ($group['items'] as $item)
                                @php
                                    $isCurrent = request()->routeIs($item['match']);
                                @endphp

                                <a
                                    href="{{ (! config('management.legacy_admin_enabled') && str_starts_with($item['route'], 'admin.') && config('management.dashboard_url')) ? config('management.dashboard_url') : route($item['route']) }}"
                                    @if ($isCurrent) aria-current="page" @endif
                                    class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition
                                           {{ $isCurrent
                                               ? 'bg-brand-50 text-brand-700'
                                               : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900' }}"
                                >
                                    <svg
                                        class="h-4 w-4 shrink-0 {{ $isCurrent ? 'text-brand-600' : 'text-ink-400' }}"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="{{ $item['icon'] }}"
                                        />
                                    </svg>

                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
        </aside>

        {{-- 移动端横向导航 --}}
        <div class="shrink-0 overflow-x-auto border-b border-ink-100 bg-white lg:hidden">
            <nav class="flex gap-0.5 px-3 py-2" aria-label="设置导航">
                @foreach ($navItems as $group)
                    @if (($group['adminOnly'] ?? false) && ! auth()->user()->isSystemAdmin())
                        @continue
                    @endif

                    @foreach ($group['items'] as $item)
                        @php
                            $isCurrent = request()->routeIs($item['match']);
                        @endphp

                        <a
                            href="{{ (! config('management.legacy_admin_enabled') && str_starts_with($item['route'], 'admin.') && config('management.dashboard_url')) ? config('management.dashboard_url') : route($item['route']) }}"
                            @if ($isCurrent) aria-current="page" @endif
                            class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium transition
                                   {{ $isCurrent
                                       ? 'bg-brand-50 text-brand-700'
                                       : 'text-ink-500 hover:bg-ink-50 hover:text-ink-700' }}"
                        >
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                @endforeach
            </nav>
        </div>

        {{--
            右侧内容区：
            1. 标题固定。
            2. 正文独立滚动。
            3. 不再在这里输出 $errors->all() 全局错误栏。
            4. 字段错误由各字段自身就地显示，避免重复提示。
        --}}
        <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            <main class="flex min-h-0 flex-1 flex-col px-4 py-6 sm:px-6 lg:px-8">
                <h1 class="shrink-0 text-xl font-semibold tracking-tight text-ink-900 sm:text-2xl">
                    @yield('page-title', '设置')
                </h1>

                <div class="mt-6 flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain pb-3 pr-1">
                    @yield('settings-content')
                </div>
            </main>
        </div>
    </div>
</div>
@endsection
