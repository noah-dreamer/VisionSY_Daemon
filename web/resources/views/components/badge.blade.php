@props(['tone' => 'gray', 'dot' => false])
@php
    $class = match ($tone) {
        'green' => 'badge-green',
        'amber' => 'badge-amber',
        'red'   => 'badge-red',
        'blue'  => 'badge-blue',
        default => 'badge-gray',
    };
@endphp
<span {{ $attributes->merge(['class' => $class . ($dot ? ' badge-dot' : '')]) }}>{{ $slot }}</span>
