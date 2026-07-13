{{--
    品牌标识占位符（VisionSY 项目 logo）。
    当前使用 .brand-mark 蓝色 "V" 字方块作为 VisionSY 项目 logo 的临时占位。
    后续如需替换为正式 logo 图片，操作方式：
        将下方 <span class="brand-mark ...">V</span> 替换为
        <img src="{{ asset('images/brand/logo.png') }}" class="h-9 w-9 object-contain" alt="VisionSY">
    并移除 .brand-mark 相关的渐变背景 class（该 class 定义于 app.css .brand-mark，
    届时如无其他引用可一并清理；当前导航栏 layouts/app.blade.php、后台 layouts/admin.blade.php、
    页脚 components/site-footer.blade.php 均在使用，请勿提前删除）。
--}}
@props([
    'dark' => false,
    'size' => 'md',      // sm | md
    'sub' => null,       // 副标题文字，null 则不显示
    'href' => null,
])
@php
    $mark = $size === 'sm' ? 'h-8 w-8 text-xs' : 'h-9 w-9 text-sm';
    $title = $dark ? 'text-white' : 'text-ink-900';
    $subColor = $dark ? 'text-white/55' : 'text-ink-400';
    $tag = $href ? 'a' : 'span';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'group inline-flex items-center gap-2.5']) }}>
    <span class="brand-mark {{ $mark }}" aria-hidden="true">V</span>
    <span class="flex flex-col leading-none">
        <span class="text-[15px] font-semibold tracking-tight {{ $title }}">VisionSY</span>
        @if($sub)
            <span class="mt-0.5 text-[11px] uppercase tracking-[0.12em] {{ $subColor }}">{{ $sub }}</span>
        @endif
    </span>
</{{ $tag }}>
