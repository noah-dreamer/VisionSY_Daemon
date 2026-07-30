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
    $isPassword = $type === 'password';
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

    @if ($isPassword)
        <div class="relative" x-data="{ passwordVisible: false }">
    @endif

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($isPassword) x-bind:type="passwordVisible ? 'text' : 'password'" @endif
        @if (! $isPassword && ! is_null($value)) value="{{ $value }}" @endif
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
               @if ($isPassword) pr-12 @endif
               @if ($mono) font-mono @endif
               {{ $hasError
                   ? 'border-red-300 bg-red-50/30 focus:border-red-500 focus:ring-red-500/20'
                   : '' }}"
    >

    @if ($isPassword)
            <button
                type="button"
                class="absolute right-1.5 top-1/2 inline-flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-xl text-ink-400 transition hover:bg-ink-50 hover:text-ink-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
                x-on:click="passwordVisible = ! passwordVisible"
                x-bind:aria-label="passwordVisible ? '隐藏{{ $label }}' : '显示{{ $label }}'"
                x-bind:title="passwordVisible ? '隐藏{{ $label }}' : '显示{{ $label }}'"
                x-bind:aria-pressed="passwordVisible"
                aria-controls="{{ $name }}"
            >
                <svg
                    x-show="! passwordVisible"
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"
                    />
                    <circle cx="12" cy="12" r="3" />
                </svg>

                <svg
                    x-show="passwordVisible"
                    x-cloak
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="1.8"
                    aria-hidden="true"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M3 3l18 18"
                    />
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M10.58 10.58a2 2 0 0 0 2.83 2.83"
                    />
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M9.88 4.24A9.8 9.8 0 0 1 12 4c5 0 9.27 3.11 11 7.5a11.6 11.6 0 0 1-2.2 3.19"
                    />
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M6.61 6.61A11.5 11.5 0 0 0 1 11.5C2.73 15.89 7 19 12 19a9.8 9.8 0 0 0 5.39-1.61"
                    />
                </svg>
            </button>
        </div>
    @endif

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
