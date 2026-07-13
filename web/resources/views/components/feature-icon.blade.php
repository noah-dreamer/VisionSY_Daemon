@props(['path'])
{{-- 统一的功能图标圆角容器 --}}
<span {{ $attributes->merge(['class' => 'flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100']) }}>
    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
    </svg>
</span>
