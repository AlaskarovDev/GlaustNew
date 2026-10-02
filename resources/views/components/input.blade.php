@props(['name', 'label' => null, 'value' => null, 'type' => 'text', 'required' => false, 'hint' => null, 'wrapper' => ''])
@php
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $current = old($errorKey, $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value);
@endphp
<x-field :label="$label" :name="$name" :hint="$hint" :required="$required" :class="$wrapper">
    @if($type === 'textarea')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $attributes->get('rows', 3) }}" @required($required)
            {{ $attributes->except('rows')->class(['input', 'is-invalid' => $errors->has($errorKey)]) }}
            @if($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $current }}</textarea>
    @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $current }}" @required($required)
            {{ $attributes->class(['input', 'is-invalid' => $errors->has($errorKey), 'font-mono tabular' => in_array($type, ['number', 'date'])]) }}
            @if($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
    @endif
</x-field>
