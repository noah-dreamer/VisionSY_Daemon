@extends('layouts.app')

@section('title', 'VisionSY · 校园媒体资产管理系统')
@section('over-hero', '1')

@section('content')
    {{-- ============================ HERO：取景器 ============================ --}}
    <section class="grain relative min-h-[94svh] overflow-hidden bg-ink-950 text-white glow-scene"
             x-data="heroCarousel(4)" data-parallax-scene data-nav-tone="dark">
        {{-- 影像轮播背景（随指针轻微移动，形成纵深） --}}
        <div class="absolute -inset-6" aria-hidden="true" data-parallax="-10">
            @foreach (['slide-1', 'slide-2', 'slide-3', 'slide-4'] as $i => $slide)
                <div class="absolute inset-0 bg-cover bg-center transition-all duration-[1200ms] ease-out"
                     :class="active === {{ $i }} ? 'opacity-100 scale-100' : 'opacity-0 scale-105'"
                     style="background-image:url('{{ asset('images/slides/'.$slide.'.svg') }}')"></div>
            @endforeach
            {{-- 压暗渐变，保证文字可读 --}}
            <div class="absolute inset-0 bg-gradient-to-r from-ink-950 via-ink-950/80 to-ink-950/30"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-ink-950 via-transparent to-ink-950/40"></div>
        </div>
        <div class="glow-layer" aria-hidden="true"></div>

        {{-- 取景器四角：顶边让出悬浮导航的高度，不与其冲突 --}}
        <div class="pointer-events-none absolute inset-x-4 bottom-4 top-[5.75rem] z-10 vf-corners sm:inset-x-6 sm:bottom-6 sm:top-24" aria-hidden="true">
            <span class="vf-b"></span>
        </div>

        <div class="relative flex min-h-[94svh] items-center">
            <div class="shell w-full pb-40 pt-32 sm:pb-44" data-parallax="5">
                <div class="max-w-3xl animate-fade-up">
                    <p class="meta-label-light mb-6">宣传部数字门户 / CAMPUS MEDIA ARCHIVE</p>
                    <h1 class="h-hero text-white">
                        校园里发生的每一幕，<br class="hidden sm:block">
                        都值得<span class="relative inline-block px-1">
                            被好好收进档案
                            <span class="absolute inset-x-1 bottom-1 -z-10 h-3 bg-brand-600/60" aria-hidden="true"></span>
                        </span>。
                    </h1>
                    <p class="mt-6 max-w-2xl text-base leading-relaxed text-white/80 sm:text-lg">
                        校园媒体资产管理系统，集中管理校园照片、视频与设计素材，让每一幕精彩都能被妥善保存。
                    </p>

                    <div class="mt-10">
                        @auth
                            <a href="{{ config('visionsy.urls.auth') }}/dashboard" class="btn-glass-capsule btn-glass-capsule-lg" data-magnetic>现在开始</a>
                        @else
                            <a href="{{ config('visionsy.urls.auth') }}/register" class="btn-glass-capsule btn-glass-capsule-lg" data-magnetic>现在开始</a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>

        {{-- 底部场记条：REC 信息，位于取景框内（轮播分页器 UI 已按第 3 轮任务 10 移除，
             背景图自动轮播状态机仍由 heroCarousel 组件驱动，见 app.js） --}}
        <div class="absolute inset-x-8 bottom-9 z-10 flex items-center justify-between sm:inset-x-12">
            <div class="hidden items-center gap-2.5 font-meta text-[11px] uppercase tracking-[0.22em] text-white/55 md:flex" aria-hidden="true">
                <span class="rec-dot animate-rec-pulse"></span>
                <span>REC · {{ date('Y.m') }} · 校园影像档案</span>
            </div>
        </div>
    </section>

    {{-- 字幕跑马灯：素材类型 --}}
    <section class="border-b border-ink-100 bg-white py-5" aria-hidden="true" data-nav-tone="light">
        <div class="marquee mask-fade-x">
            <div class="marquee-track font-meta text-xs uppercase tracking-[0.28em] text-ink-400">
                @foreach (range(1, 2) as $half)
                    @foreach (range(1, 2) as $rep)
                        @foreach (['校园活动照片 / PHOTO', '宣传片与短片 / VIDEO', '海报与设计源文件 / DESIGN', '典礼与赛事记录 / EVENT', '人物专题 / PORTRAIT', '校史影像 / ARCHIVE'] as $tag)
                            <span class="flex items-center gap-10">
                                <span>{{ $tag }}</span>
                                <span class="h-1 w-1 rounded-full bg-ink-300"></span>
                            </span>
                        @endforeach
                    @endforeach
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================ 能力索引 ============================ --}}
    <section id="capabilities" class="relative overflow-hidden bg-ink-50" data-nav-tone="light">
        <div class="shell relative py-20 sm:py-24">
            <div class="grid gap-12 lg:grid-cols-[minmax(0,20rem)_1fr] lg:gap-20">
                <div class="lg:sticky lg:top-32 lg:self-start">
                    <x-section-heading eyebrow="能力索引 / INDEX">
                        <x-slot:title>素材不再散落在<br>各自的硬盘里</x-slot:title>
                        <x-slot:description>一套校园媒体资产管理平台，集中存储照片、视频与设计素材，让全校师生都能便捷地找到并使用需要的媒体资源。</x-slot:description>
                    </x-section-heading>
                    <p class="mt-8 hidden font-meta text-[11px] uppercase tracking-[0.22em] text-ink-400 lg:block" data-reveal data-reveal-delay="200">
                        04 项核心能力 · 逐条查阅
                    </p>
                </div>

                <div data-reveal-stagger="110">
                    @php
                        $features = [
                            ['tag' => '下载 / TIER', 'title' => '按角色分级下载', 'desc' => '宣传部与官微老师取无水印原图，普通师生取带水印压缩图——权限跟着身份走，不用人工审批每一次下载。'],
                            ['tag' => '水印 / MARK', 'title' => '预览图自动叠水印', 'desc' => '预览与压缩图实时叠加水印，原图只对授权账号开放。素材真流出去了，也能顺着水印追回来源。'],
                            ['tag' => '激活 / CAMPUS', 'title' => '连校园网即激活', 'desc' => '邮箱自助注册后，在校园网环境登录一次就自动激活，把账号自然圈定在校内人员，管理员不用逐个核对。'],
                            ['tag' => '直达 / ONE ACCOUNT', 'title' => '一个账号进媒体库', 'desc' => '用官网账号一键进入媒体库，不用再记第二套密码，权限按你的身份自动配好。'],
                        ];
                    @endphp
                    @foreach ($features as $f)
                        <div class="index-row card-spot group px-2 sm:px-4" data-reveal>
                            <span class="font-meta text-[11px] uppercase tracking-[0.2em] text-ink-400 transition-colors group-hover:text-brand-600">{{ $f['tag'] }}</span>
                            <div>
                                <h3 class="font-display text-lg font-semibold tracking-tight text-ink-950 sm:text-xl">{{ $f['title'] }}</h3>
                                <p class="mt-2 max-w-xl prose-muted">{{ $f['desc'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============================ 面向不同角色 ============================ --}}
    <section class="border-t border-ink-100 bg-white" data-nav-tone="light">
        <div class="shell py-20 sm:py-24">
            <div class="grid gap-12 lg:grid-cols-2">
                <x-section-heading eyebrow="使用者 / ACCESS">
                    <x-slot:title>谁能拿到什么，<br>提前说清楚</x-slot:title>
                    <x-slot:description>从拍摄、归档到分发，每一类用户都有明确的权限边界，不靠口头约定。</x-slot:description>
                </x-section-heading>

                <div class="grid gap-4 sm:grid-cols-2" data-reveal-stagger="100">
                    @php
                        $roles = [
                            ['name' => '普通师生', 'tone' => 'amber', 'desc' => '邮箱注册 + 校园网激活，浏览并下载带水印压缩图。'],
                            ['name' => '宣传部成员', 'tone' => 'blue', 'desc' => '实名账号，上传素材、下载无水印原图。'],
                            ['name' => '官微编辑老师', 'tone' => 'green', 'desc' => '实名账号，下载无水印原图用于官方发布。'],
                            ['name' => '系统管理员', 'tone' => 'gray', 'desc' => '管理用户、校园网段、OAuth 客户端与审计日志。'],
                        ];
                    @endphp
                    @foreach ($roles as $r)
                        <div class="card-quiet card-spot p-5 transition-colors duration-300 hover:border-ink-200" data-reveal>
                            <x-badge :tone="$r['tone']">{{ $r['name'] }}</x-badge>
                            <p class="mt-3 prose-muted">{{ $r['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============================ 影像档案（项目展示） ============================ --}}
    <section id="projects" class="grain relative overflow-hidden bg-ink-950 text-white glow-scene" data-nav-tone="dark">
        <span class="ghost-char ghost-char-light absolute -left-10 bottom-0 text-[17rem] lg:text-[22rem]" aria-hidden="true">录</span>
        <div class="glow-layer" aria-hidden="true"></div>
        <div class="shell relative py-20 sm:py-24">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <x-section-heading eyebrow="影像档案 / SELECTED" invert>
                    <x-slot:title>校园影像的<br>集中展示</x-slot:title>
                    <x-slot:description>校园活动图库、宣传片、大型活动影像记录——展示校园精彩瞬间，沉淀为可长期检索的影像资产。</x-slot:description>
                </x-section-heading>
            </div>

            @php
                $projects = [
                    ['tag' => 'ARCHIVE', 'title' => '校园活动图库', 'desc' => '照片、视频与设计素材集中归档，成员检索、复用与交接都有据可查。', 'slide' => 'slide-1'],
                    ['tag' => 'VIDEO', 'title' => '宣传片与专题短片', 'desc' => '围绕学校品牌、重大活动与人物故事，制作更具传播力的视觉内容。', 'slide' => 'slide-4'],
                    ['tag' => 'EVENT', 'title' => '大型活动影像记录', 'desc' => '为典礼、比赛、晚会与展演提供拍摄支持，沉淀可展示的作品集。', 'slide' => 'slide-3'],
                ];
            @endphp
            {{-- 编辑部式的不对称排版：主图占两列，两张附图右侧竖排 --}}
            <div class="mt-12 grid gap-6 md:grid-cols-3" data-reveal-stagger="120">
                @foreach ($projects as $i => $p)
                    <article class="group card-spot card-spot-dark flex flex-col overflow-hidden rounded-3xl border border-white/10 bg-white/[0.04] transition duration-300 hover:-translate-y-1 hover:border-white/25 {{ $i === 0 ? 'md:col-span-2 md:row-span-2' : '' }}"
                             data-reveal>
                        <div class="relative {{ $i === 0 ? 'min-h-[16rem] flex-1' : 'aspect-[4/3]' }} overflow-hidden">
                            <img src="{{ asset('images/slides/'.$p['slide'].'.svg') }}" alt="{{ $p['title'] }}"
                                 class="absolute inset-0 h-full w-full object-cover transition duration-700 ease-out group-hover:scale-[1.06]">
                            <span class="absolute left-3 top-3 font-meta text-[10px] uppercase tracking-[0.2em] text-white/70">
                                No.{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }} · {{ $p['tag'] }}
                            </span>
                        </div>
                        <div class="{{ $i === 0 ? 'p-7' : 'p-6' }}">
                            <h3 class="font-display font-semibold tracking-tight {{ $i === 0 ? 'text-2xl' : 'text-lg' }}">{{ $p['title'] }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-white/55">{{ $p['desc'] }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================ 三步流程（真实顺序，保留编号） ============================ --}}
    <section class="border-t border-ink-100 bg-white" data-nav-tone="light">
        <div class="shell py-20 sm:py-24">
            <x-section-heading eyebrow="上手流程 / 3 STEPS" center>
                <x-slot:title>从注册到进库，三步</x-slot:title>
            </x-section-heading>

            <div class="relative mx-auto mt-14 grid max-w-4xl gap-10 sm:grid-cols-3" data-reveal-stagger="140">
                @php
                    $steps = [
                        ['t' => '注册与验证', 'd' => '用邮箱注册账号，点开验证邮件里的链接。'],
                        ['t' => '校园网激活', 'd' => '连上校园网登录官网一次，系统自动完成账号激活。'],
                        ['t' => '进入媒体库', 'd' => '点「进入媒体库」，免密直达检索与下载。'],
                    ];
                @endphp
                @foreach ($steps as $i => $s)
                    <div class="relative text-center" data-reveal>
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl border border-ink-200 bg-white font-meta text-xs font-semibold text-brand-600 shadow-soft">{{ str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) }}</div>
                        <h3 class="mt-4 h-card">{{ $s['t'] }}</h3>
                        <p class="mx-auto mt-2 max-w-xs prose-muted">{{ $s['d'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-12 flex justify-center" data-reveal data-reveal-delay="300">
                @guest
                    <a href="{{ config('visionsy.urls.auth') }}/register" class="btn-primary" data-magnetic>立即注册</a>
                @else
                    <a href="{{ config('visionsy.urls.auth') }}/dashboard" class="btn-primary" data-magnetic>前往控制台</a>
                @endguest
            </div>
        </div>
    </section>

    {{-- ============================ 收尾 CTA：影像之上的玻璃面板 ============================ --}}
    <section class="bg-ink-50" data-nav-tone="light">
        <div class="shell pb-24 pt-4">
            <div class="grain relative overflow-hidden rounded-4xl bg-ink-950 glass-panel-frame" data-reveal data-parallax-scene>
                {{-- 校园实景 background.png 作为深色面板背景；glass-cta-bg 在渲染时调暗+裁边，
                     配合下方加深的渐变遮罩压住图片高光，避免整体发白（architecture.md 6.2 主选方案） --}}
                <div class="glass-cta-bg absolute -inset-[6%] bg-cover bg-center" data-parallax="-6"
                     style="background-image:url('{{ asset('images/brand/background.webp') }}')" aria-hidden="true"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-ink-950/[0.94] via-ink-950/[0.55] to-ink-950/[0.45]" aria-hidden="true"></div>
                <div class="pointer-events-none absolute inset-4 vf-corners opacity-60" aria-hidden="true"><span class="vf-b"></span></div>

                <div class="relative flex justify-center px-6 py-16 sm:px-12 sm:py-20">
                    <div class="liquid-glass w-full max-w-2xl rounded-3xl px-8 py-14 text-center sm:px-12">
                        <div class="relative z-10 flex flex-col items-center">
                            <p class="meta-label-light mb-5 justify-center">下一帧，由你记录</p>
                            <h2 class="h-section text-white">准备好集中管理校园影像了吗？</h2>
                            <p class="mx-auto mt-4 max-w-md text-base leading-relaxed text-white/70">注册账号，连接校园网激活，即可一键进入媒体库。</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
