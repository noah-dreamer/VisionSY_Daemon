@extends('layouts.app')

@section('content')
<div class="flex flex-1 flex-col lg:flex-row">
    {{-- 桌面端侧边栏 --}}
    <aside class="hidden w-60 shrink-0 border-r border-ink-100 bg-white lg:block" aria-label="设置导航">
        <nav class="sticky top-16 space-y-6 px-3 py-6">
            @foreach ($navItems as $group)
                @if (($group['adminOnly'] ?? false) && ! auth()->user()->isSystemAdmin())
                    @continue
                @endif
                <div>
                    <h3 class="px-3 text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">{{ $group['label'] }}</h3>
                    <div class="mt-2 space-y-0.5">
                        @foreach ($group['items'] as $item)
                            @php $isCurrent = request()->routeIs($item['match']); @endphp
                            <a href="{{ route($item['route']) }}"
                               @if($isCurrent) aria-current="page" @endif
                               class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition
                                      {{ $isCurrent
                                          ? 'bg-brand-50 text-brand-700'
                                          : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900' }}">
                                <svg class="h-4 w-4 shrink-0 {{ $isCurrent ? 'text-brand-600' : 'text-ink-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
    </aside>

    {{-- 移动端横向 tab 导航 --}}
    <div class="overflow-x-auto border-b border-ink-100 bg-white lg:hidden">
        <nav class="flex gap-0.5 px-3 py-2" aria-label="设置导航">
            @foreach ($navItems as $group)
                @if (($group['adminOnly'] ?? false) && ! auth()->user()->isSystemAdmin())
                    @continue
                @endif
                @foreach ($group['items'] as $item)
                    @php $isCurrent = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @if($isCurrent) aria-current="page" @endif
                       class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium whitespace-nowrap transition
                              {{ $isCurrent
                                  ? 'bg-brand-50 text-brand-700'
                                  : 'text-ink-500 hover:bg-ink-50 hover:text-ink-700' }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach
            @endforeach
        </nav>
    </div>

    {{-- 右侧内容区 --}}
    <div class="min-w-0 flex-1">
        <header class="sticky top-12 z-20 flex h-14 items-center border-b border-ink-100 bg-white/85 px-4 backdrop-blur-xl sm:px-6">
            <h1 class="text-base font-semibold tracking-tight text-ink-900">@yield('page-title', '设置')</h1>
        </header>

        <main class="px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl space-y-6">
                @if (session('status'))
                    <div class="alert-success" role="status">
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

                @yield('settings-content')
            </div>
        </main>
    </div>
</div>
@endsection
