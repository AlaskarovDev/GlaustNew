@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'required' => false, 'hint' => null, 'wrapper' => '', 'fresh' => false, 'id' => null])
@php
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $current = (string) ($fresh ? $value : old($errorKey, $value));
    $id ??= $name;
@endphp
<x-field :label="$label" :name="$name" :hint="$hint" :required="$required" :class="$wrapper" :for="$id">
    <select id="{{ $id }}" name="{{ $name }}" @required($required)
        {{ $attributes->class(['input', 'is-invalid' => $errors->has($errorKey)]) }}
        @if($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
        @if($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach($options as $key => $text)
            <option value="{{ $key }}" @selected($current === (string) $key)>{{ $text }}</option>
        @endforeach
    </select>
</x-field>
