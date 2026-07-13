<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="VisionSY 校园媒体资产管理系统 · 统一归档、分级取用、校园网激活。">
    <title>@yield('title', 'VisionSY · 校园媒体资产管理系统')</title>
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
        class="fixed inset-x-0 top-0 z-50 px-3 pt-3 sm:px-5 sm:pt-4">

        {{-- nav-bar 标识顶部导航条（区别于下方移动抽屉）。
             nav-tone-{dark|light} 由 app.js initNavToneAwareness 按背后 section 深浅写入 header，
             --nav-fg / --nav-fg-muted 在 app.css 内据此切换文字与玻璃配色。 --}}
        <div class="nav-bar liquid-glass-dark mx-auto flex h-12 max-w-shell items-center justify-between px-4 transition-all duration-300 sm:px-5">

            <a href="{{ route('home') }}" class="group relative z-10 inline-flex items-center gap-2.5">
                {{-- VisionSY 品牌 logo 占位符：当前用 .brand-mark 蓝色 "V" 字方块占位，
                     后续替换为正式 logo 见 components/brand.blade.php 顶部注释。 --}}
                <span class="brand-mark h-9 w-9 text-sm" aria-hidden="true">V</span>
                <span class="flex flex-col leading-none">
                    <span class="text-[15px] font-semibold tracking-tight text-[color:var(--nav-fg)]">VisionSY</span>
                    <span class="mt-0.5 hidden font-meta text-[10px] uppercase tracking-[0.14em] text-[color:var(--nav-fg-muted)] sm:block">Campus Media Archive</span>
                </span>
            </a>

            {{-- 桌面操作区：独立液态玻璃胶囊按钮 --}}
            <nav class="relative z-10 hidden items-center gap-2 sm:flex" aria-label="主导航">
                <a href="{{ route('media.index') }}" class="capsule-bar-btn-dark">媒体库</a>
                @auth
                    <a href="{{ config('visionsy.urls.auth') }}/dashboard"
                       class="capsule-bar-btn-dark">控制台</a>
                    @if (auth()->user()->isSystemAdmin())
                        <a href="{{ config('visionsy.urls.auth') }}/admin"
                           class="capsule-bar-btn-dark">管理后台</a>
                    @endif
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
                    <a href="{{ config('visionsy.urls.auth') }}/dashboard" class="rounded-xl px-3 py-2.5 text-sm font-medium text-white hover:bg-white/10">控制台</a>
                    @if (auth()->user()->isSystemAdmin())
                        <a href="{{ config('visionsy.urls.auth') }}/admin" class="rounded-xl px-3 py-2.5 text-sm font-medium text-white hover:bg-white/10">管理后台</a>
                    @endif
                    <a href="{{ route('media.index') }}" class="rounded-xl px-3 py-2.5 text-sm font-medium text-white hover:bg-white/10">媒体库</a>
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
    <main id="main" class="flex-1 {{ $overHero ? '' : 'pt-16' }}" data-nav-tone="{{ $overHero ? 'dark' : 'light' }}">
        @if (session('status'))
            <div class="shell pt-6">
                <div class="alert-success" role="status">
                    <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <span>{{ session('status') }}</span>
                </div>
            </div>
        @endif

        @yield('content')
    </main>

    <x-site-footer />
</body>
</html>
