<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'VisionSY · 校园媒体资产管理系统')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-900">
    <div class="flex min-h-screen flex-col lg:flex-row">
        {{-- 左侧：品牌影像面（桌面端） --}}
        <aside class="glow-scene relative hidden overflow-hidden bg-ink-950 lg:flex lg:w-1/2 lg:flex-col">
            <div class="absolute inset-0 bg-[radial-gradient(60rem_40rem_at_20%_15%,rgba(53,118,245,0.35),transparent),radial-gradient(50rem_40rem_at_90%_90%,rgba(124,92,255,0.28),transparent)]"></div>
            <div class="absolute inset-0 bg-grid-dark opacity-60"></div>
            <div class="glow-layer" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-5 vf-corners opacity-50" aria-hidden="true"><span class="vf-b"></span></div>
            <div class="relative flex flex-1 flex-col justify-between p-12">
                <a href="{{ route('home') }}"><x-brand dark sub="校园媒体资产管理" /></a>

                <div class="max-w-md">
                    <p class="meta-label-light mb-5">宣传部数字门户 / CAMPUS MEDIA ARCHIVE</p>
                    <h2 class="font-display text-4xl font-semibold leading-snug tracking-tight text-white">
                        把校园的每一次发生，<br>留存为可检索的影像。
                    </h2>
                    <p class="mt-5 text-base leading-relaxed text-white/60">
                        统一归档、分级取用、自动水印，校园网环境一次登录即可激活账号，免密直达 ResourceSpace 媒体库。
                    </p>
                    <dl class="mt-10 grid grid-cols-3 gap-4">
                        @foreach (['分级下载', '自动水印', '校园 IP 激活'] as $f)
                            <div class="glass-dark px-3 py-3 text-center">
                                <dt class="text-xs text-white/80">{{ $f }}</dt>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <p class="text-xs text-white/40">© {{ date('Y') }} VisionSY · 学校宣传部</p>
            </div>
        </aside>

        {{-- 右侧：表单 --}}
        <main class="flex flex-1 flex-col">
            <div class="flex items-center justify-between p-6 lg:hidden">
                <a href="{{ route('home') }}"><x-brand sub="校园媒体资产管理" /></a>
            </div>

            <div class="flex flex-1 items-center justify-center px-5 py-10 sm:px-8">
                <div class="w-full max-w-md">
                    @if (session('status'))
                        <div class="alert-success mb-6" role="status">
                            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            <span>{{ session('status') }}</span>
                        </div>
                    @endif
                    @yield('content')
                </div>
            </div>
        </main>
    </div>
</body>
</html>
