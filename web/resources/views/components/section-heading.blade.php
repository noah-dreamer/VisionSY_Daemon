@props(['eyebrow' => null, 'invert' => false, 'center' => false])
<div {{ $attributes->merge(['class' => $center ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl']) }} data-reveal>
    @if($eyebrow)
        <p class="{{ $invert ? 'meta-label-light' : 'meta-label' }} mb-4 {{ $center ? 'justify-center' : '' }}">{{ $eyebrow }}</p>
    @endif
    <h2 class="h-section {{ $invert ? 'text-white' : 'text-ink-950' }}">{{ $title ?? $slot }}</h2>
    @isset($description)
        <p class="mt-4 text-base leading-relaxed {{ $invert ? 'text-white/60' : 'text-ink-600' }}">{{ $description }}</p>
    @endisset
</div>
