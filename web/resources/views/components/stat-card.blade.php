@props(['label', 'value', 'tone' => 'text-ink-900', 'hint' => null])
<div class="card p-5">
    <p class="text-xs font-medium uppercase tracking-wider text-ink-400">{{ $label }}</p>
    <p class="mt-2 text-3xl font-semibold tabular-nums {{ $tone }}">{{ $value }}</p>
    @if($hint)<p class="mt-1 text-xs text-ink-400">{{ $hint }}</p>@endif
</div>
