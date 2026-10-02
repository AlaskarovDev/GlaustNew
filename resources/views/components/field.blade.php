@props(['label' => null, 'name' => null, 'hint' => null, 'required' => false, 'for' => null])
@php $errorKey = $name ? str_replace(['[', ']'], ['.', ''], $name) : null; @endphp
<div {{ $attributes }}>
    @if($label)
        <label class="field-label" @if($for ?? $name) for="{{ $for ?? $name }}" @endif>
            {{ $label }} @if($required)<span class="text-danger" aria-hidden="true">*</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if($errorKey)
        @error($errorKey)
            <p class="field-error" id="{{ $name }}-error"><x-icon name="alert" class="size-3.5 shrink-0"/> {{ $message }}</p>
        @enderror
    @endif
    @if($hint)
        <p class="field-hint">{{ $hint }}</p>
    @endif
</div>
