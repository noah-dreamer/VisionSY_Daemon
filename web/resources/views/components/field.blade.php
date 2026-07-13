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
<div {{ $attributes->only('class') }}>
    <div class="flex items-center justify-between">
        <label for="{{ $name }}" class="form-label">{{ $label }}</label>
        @isset($action){{ $action }}@endisset
    </div>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if(!is_null($value)) value="{{ $value }}" @endif
        @if($placeholder) placeholder="{{ $placeholder }}" @endif
        @if($required) required @endif
        @if($autofocus) autofocus @endif
        @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if($maxlength) maxlength="{{ $maxlength }}" @endif
        class="form-input @if($mono) font-mono @endif"
    >
    @if($hint)<p class="form-hint">{{ $hint }}</p>@endif
    @error($name)<p class="form-error">{{ $message }}</p>@enderror
</div>
