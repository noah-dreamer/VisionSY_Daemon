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
    <img src="{{ asset('images/brand/logo.png') }}" class="{{ $mark }} object-contain" alt="VisionSY">
    <span class="flex flex-col leading-none">
        <span class="text-[15px] font-semibold tracking-tight {{ $title }}">VisionSY</span>
        @if($sub)
            <span class="mt-0.5 text-[11px] uppercase tracking-[0.12em] {{ $subColor }}">{{ $sub }}</span>
        @endif
    </span>
</{{ $tag }}>
