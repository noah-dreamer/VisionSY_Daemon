<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="VisionSY 校园媒体资产管理系统 · 统一归档、分级取用、校园网激活。">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=1" sizes="any">
    <title>@yield('title', 'VisionSY · 校园媒体资产管理系统')</title>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-ink-50 text-ink-900">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[80] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:shadow-lift">跳转到主要内容</a>

    {{-- 阅读进度条（app.js 更新 --scroll-progress） --}}
    <div id="scroll-progress" class="fixed inset-x-0 top-0 z-[70] h-[2px] bg-brand-500" aria-hidden="true"></div>

    {{-- 液态玻璃边缘透镜滤镜（Apple Liquid Glass 复刻，方案参照 shuding/liquid-glass、kube.io）。
         仅被 .supports-refraction .nav-bar.liquid-glass-dark 通过 backdrop-filter:url(#liquid-lens) 引用。
         位移贴图由 app.js 的 initLiquidGlassLens 在运行时按导航条实际宽高 + 圆角，
         用圆角矩形 SDF（有向距离场）逐像素计算生成（canvas → dataURL 写入 #liquid-lens-map）：
         中心区域零位移，边缘 bezel 带内把背后像素沿边缘法线向内拉（凸透镜放大观感）。
         贴图注入完成后 app.js 才给 <html> 加 .supports-refraction，因此这里 feImage 无初始 href
         也不会闪现坏帧；探针检测不通过的浏览器（Safari/Firefox）永远走纯毛玻璃回退。 --}}
    <svg class="fixed h-0 w-0" aria-hidden="true" focusable="false">
        <defs>
            {{-- RGB 三通道分别以 100% / 93% / 86% 的位移强度折射后重组（feBlend screen），
                 模拟真实玻璃的边缘色散（chromatic aberration）：折射带内出现细微彩边。
                 三个 scale 值由 app.js 按 MAX_SHIFT 统一写入。 --}}
            <filter id="liquid-lens" x="0%" y="0%" width="100%" height="100%" color-interpolation-filters="sRGB">
                <feImage id="liquid-lens-map" x="0" y="0" width="100%" height="100%" preserveAspectRatio="none" result="map"/>
                <feDisplacementMap id="liquid-lens-dr" in="SourceGraphic" in2="map" scale="0" xChannelSelector="R" yChannelSelector="G" result="dispR"/>
                <feColorMatrix in="dispR" type="matrix" values="1 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 1 0" result="chR"/>
                <feDisplacementMap id="liquid-lens-dg" in="SourceGraphic" in2="map" scale="0" xChannelSelector="R" yChannelSelector="G" result="dispG"/>
                <feColorMatrix in="dispG" type="matrix" values="0 0 0 0 0  0 1 0 0 0  0 0 0 0 0  0 0 0 1 0" result="chG"/>
                <feDisplacementMap id="liquid-lens-db" in="SourceGraphic" in2="map" scale="0" xChannelSelector="R" yChannelSelector="G" result="dispB"/>
                <feColorMatrix in="dispB" type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 1 0 0  0 0 0 1 0" result="chB"/>
                <feBlend mode="screen" in="chR" in2="chG" result="chRG"/>
                <feBlend mode="screen" in="chRG" in2="chB"/>
            </filter>
        </defs>
    </svg>

    @php $overHero = trim($__env->yieldContent('over-hero')) === '1'; @endphp

    {{-- ==================== 悬浮液态玻璃导航（背景感知自适应亮暗） ==================== --}}
    <header
        x-data="{ menu: false }"
        @keydown.escape.window="menu = false"
        class="fixed inset-x-0 top-0 z-50 px-3 pt-3 sm:px-5 sm:pt-4 {{ $overHero ? 'nav-tone-dark' : 'nav-tone-light' }}">

        {{-- nav-bar 标识顶部导航条（区别于下方移动抽屉）。
             nav-tone-{dark|light} 由 app.js initNavToneAwareness 按背后 section 深浅写入 header，
             --nav-fg / --nav-fg-muted 在 app.css 内据此切换文字与玻璃配色。 --}}
        <div class="nav-bar liquid-glass-dark mx-auto flex h-12 max-w-shell items-center justify-between px-4 transition-all duration-300 sm:px-5">

            <a href="{{ route('home') }}" class="group relative z-10 inline-flex items-center gap-2.5">
                <img src="{{ asset('images/brand/logo.png') }}" class="h-9 w-9 object-contain" alt="VisionSY">
                <span class="flex flex-col leading-none">
                    <span class="text-[15px] font-semibold tracking-tight text-[color:var(--nav-fg)]">VisionSY</span>
                    <span class="mt-0.5 hidden font-meta text-[10px] uppercase tracking-[0.14em] text-[color:var(--nav-fg-muted)] sm:block">Campus Media Archive</span>
                </span>
            </a>

            {{-- 桌面操作区：独立液态玻璃胶囊按钮 --}}
            <nav class="relative z-10 hidden items-center gap-2 sm:flex" aria-label="主导航">
                @auth
                    <a href="{{ config('visionsy.urls.auth') }}/dashboard"
                       class="capsule-bar-btn-dark">开始使用</a>
                    <a href="#" class="capsule-bar-btn-dark">用户指南</a>
                    <form method="POST" action="{{ route('logout') }}" class="ml-1">
                        @csrf
                        <button type="submit" class="capsule-bar-btn-dark-filled">退出</button>
                    </form>
                @else
                    <a href="{{ config('visionsy.urls.auth') }}/login"
                       class="capsule-bar-btn-dark">登录</a>
                    <a href="{{ config('visionsy.urls.auth') }}/register"
                       class="capsule-bar-btn-dark-filled">注册账号</a>
                @endauth
            </nav>

            {{-- 移动端菜单按钮 --}}
            <button type="button" class="relative z-10 -mr-1 inline-flex h-10 w-10 items-center justify-center rounded-xl transition text-[color:var(--nav-fg)] hover:bg-white/10 sm:hidden"
                    @click="menu = !menu" :aria-expanded="menu" aria-label="打开菜单">
                <svg x-show="!menu" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                <svg x-show="menu" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        {{-- 移动端抽屉：独立悬浮玻璃面板 --}}
        <div x-show="menu" x-cloak
             x-transition:enter="transition duration-200 ease-out"
             x-transition:enter-start="-translate-y-2 opacity-0"
             x-transition:enter-end="translate-y-0 opacity-100"
             x-transition:leave="transition duration-150 ease-in"
             x-transition:leave-start="translate-y-0 opacity-100"
             x-transition:leave-end="-translate-y-2 opacity-0"
             class="liquid-glass-dark mx-auto mt-2 max-w-shell rounded-2xl sm:hidden">
            <nav class="relative z-10 flex flex-col gap-1 p-3" aria-label="移动端导航">
                @auth
                    <a href="{{ config('visionsy.urls.auth') }}/dashboard" class="rounded-xl px-3 py-2.5 text-sm font-medium text-white hover:bg-white/10">开始使用</a>
                    <a href="#" class="rounded-xl px-3 py-2.5 text-sm font-medium text-white hover:bg-white/10">用户指南</a>
                    <form method="POST" action="{{ route('logout') }}" class="mt-1">
                        @csrf
                        <button type="submit" class="capsule-bar-btn-dark w-full">退出登录</button>
                    </form>
                @else
                    <a href="{{ route('media.index') }}" class="rounded-xl px-3 py-2.5 text-sm font-medium text-white hover:bg-white/10">媒体库</a>
                    <a href="{{ config('visionsy.urls.auth') }}/login" class="capsule-bar-btn-dark mt-1 w-full">登录</a>
                    <a href="{{ config('visionsy.urls.auth') }}/register" class="capsule-bar-btn-dark-filled mt-1 w-full">注册账号</a>
                @endauth
            </nav>
        </div>
    </header>

    {{-- 内页（非 hero 页）默认 light tone：悬浮导航呈现与首页白底区一致的熏色玻璃质感；
         带 hero 的页面由各 section 自行声明 data-nav-tone。 --}}
    <main id="main" class="flex flex-1 flex-col {{ $overHero ? '' : 'pt-16' }}" data-nav-tone="{{ $overHero ? 'dark' : 'light' }}">
        @yield('content')
    </main>

    @hasSection('no-footer')
    @else
        <x-site-footer />
    @endif

    @php
        $toastMessage = session('status')
            ?: ($errors->has('status') ? $errors->first('status') : null);

        $toastTone = session('status')
            ? 'success'
            : ($errors->has('status') ? 'error' : null);
    @endphp

    @if ($toastMessage)
        <div
            x-data="{
                show: false,
                total: 4000,
                remaining: 4000,
                progress: 100,
                lastTick: null,
                interval: null,

                init() {
                    requestAnimationFrame(() => {
                        this.show = true;
                        this.resume();
                    });
                },

                consumeElapsed() {
                    const now = Date.now();

                    if (this.lastTick !== null) {
                        this.remaining = Math.max(
                            0,
                            this.remaining - (now - this.lastTick)
                        );
                    }

                    this.lastTick = now;
                    this.progress = (this.remaining / this.total) * 100;
                },

                pause() {
                    if (! this.interval) return;

                    this.consumeElapsed();
                    clearInterval(this.interval);
                    this.interval = null;

                    if (this.remaining <= 0) {
                        this.show = false;
                    }
                },

                resume() {
                    if (! this.show || this.interval || this.remaining <= 0) {
                        return;
                    }

                    this.lastTick = Date.now();

                    this.interval = setInterval(() => {
                        this.consumeElapsed();

                        if (this.remaining <= 0) {
                            this.close();
                        }
                    }, 30);
                },

                close() {
                    if (this.interval) {
                        clearInterval(this.interval);
                        this.interval = null;
                    }

                    this.show = false;
                }
            }"
            x-show="show"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-y-3 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 translate-y-0 scale-100"
            x-transition:leave-end="opacity-0 -translate-y-2 scale-95"
            @mouseenter="pause"
            @mouseleave="resume"
            @keydown.escape.window="close"
            class="fixed right-4 top-20 z-[75] w-[calc(100%-2rem)] max-w-sm overflow-hidden rounded-2xl border bg-white shadow-card sm:right-6
                   {{ $toastTone === 'error'
                       ? 'border-red-200'
                       : 'border-emerald-200' }}"
            role="{{ $toastTone === 'error' ? 'alert' : 'status' }}"
            x-cloak
        >
            <div class="flex items-start gap-3 px-4 py-3.5">
                <div
                    class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full
                           {{ $toastTone === 'error'
                               ? 'bg-red-100 text-red-600'
                               : 'bg-emerald-100 text-emerald-600' }}"
                >
                    @if ($toastTone === 'error')
                        <svg
                            class="h-3.5 w-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2.4"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"
                            />
                        </svg>
                    @else
                        <svg
                            class="h-3.5 w-3.5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="3"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="m4.5 12.75 6 6 9-13.5"
                            />
                        </svg>
                    @endif
                </div>

                <p class="flex-1 pt-px text-sm text-ink-800">
                    {{ $toastMessage }}
                </p>

                <button
                    type="button"
                    @click="close"
                    class="-m-1 shrink-0 rounded-lg p-1 text-ink-400 transition hover:bg-ink-100 hover:text-ink-700"
                    aria-label="关闭提示"
                >
                    <svg
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M6 18 18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>

            <div
                class="h-1 w-full
                       {{ $toastTone === 'error'
                           ? 'bg-red-100'
                           : 'bg-emerald-100' }}"
            >
                <div
                    class="h-full transition-[width] duration-100 ease-linear
                           {{ $toastTone === 'error'
                               ? 'bg-red-500'
                               : 'bg-emerald-500' }}"
                    :style="`width: ${progress}%`"
                ></div>
            </div>
        </div>
    @endif

    @stack('scripts')
</body>
</html>
