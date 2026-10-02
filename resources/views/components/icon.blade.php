@props(['name', 'stroke' => 1.75])
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'size-5']) }}>{!! \App\Support\Icons::PATHS[$name] ?? \App\Support\Icons::PATHS['circle'] !!}</svg>
