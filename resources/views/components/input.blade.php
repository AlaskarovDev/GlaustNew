{{-- fresh: ignore old() — for several forms with the same field names on one page (the caller decides the value) --}}
@props(['name', 'label' => null, 'value' => null, 'type' => 'text', 'required' => false, 'hint' => null, 'wrapper' => '', 'fresh' => false, 'id' => null])
@php
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    $current = $fresh ? $value : old($errorKey, $value);
    $id ??= $name;
@endphp
<x-field :label="$label" :name="$name" :hint="$hint" :required="$required" :class="$wrapper" :for="$id">
    @if($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $attributes->get('rows', 3) }}" @required($required)
            {{ $attributes->except('rows')->class(['input', 'is-invalid' => $errors->has($errorKey)]) }}
            @if($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>{{ $current }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $current }}" @required($required)
            {{ $attributes->class(['input', 'is-invalid' => $errors->has($errorKey), 'font-mono tabular' => in_array($type, ['number', 'date'])]) }}
            @if($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
    @endif
</x-field>
