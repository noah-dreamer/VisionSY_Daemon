@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'required' => false,
    'autocomplete' => null,
    'autofocus' => false,
    'maxlength' => null,
    'hint' => null,
    'mono' => false,
])

@php
    $hasError = $errors->has($name);
    $hintId = $name . '-hint';
    $errorId = $name . '-error';
@endphp

<div {{ $attributes->only('class') }}>
    <div class="flex items-center justify-between">
        <label for="{{ $name }}" class="form-label">
            {{ $label }}
        </label>

        @isset($action)
            {{ $action }}
        @endisset
    </div>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if (! is_null($value)) value="{{ $value }}" @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @if ($required) required @endif
        @if ($autofocus) autofocus @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($maxlength) maxlength="{{ $maxlength }}" @endif
        @if ($hasError)
            aria-invalid="true"
            aria-describedby="{{ $errorId }}"
        @elseif ($hint)
            aria-describedby="{{ $hintId }}"
        @endif
        class="form-input
               @if ($mono) font-mono @endif
               {{ $hasError
                   ? 'border-red-300 bg-red-50/30 focus:border-red-500 focus:ring-red-500/20'
                   : '' }}"
    >

    @if ($hint)
        <p id="{{ $hintId }}" class="form-hint">
            {{ $hint }}
        </p>
    @endif

    @error($name)
        <p id="{{ $errorId }}" class="form-error" role="alert">
            {{ $message }}
        </p>
    @enderror
</div>
