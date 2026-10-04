@props(['user', 'size' => 'md'])
@php
    $dim = ['xs' => 'size-6 text-[10px]', 'sm' => 'size-7 text-[11px]', 'md' => 'size-9 text-xs', 'lg' => 'size-12 text-sm', 'xl' => 'size-16 text-lg'][$size] ?? 'size-9 text-xs';
    $hue = $user?->avatarHue() ?? 200;
@endphp
@if($user)
    <span {{ $attributes->merge(['class' => "inline-grid place-items-center shrink-0 rounded-full font-semibold select-none $dim"]) }}
          style="background: hsl({{ $hue }} 55% 88%); color: hsl({{ $hue }} 45% 28%);" title="{{ $user->name }}">{{ $user->initials() }}</span>
@else
    <span {{ $attributes->merge(['class' => "inline-grid place-items-center shrink-0 rounded-full bg-surface-2 text-faint border border-dashed border-line-strong $dim"]) }} title="{{ __('Təyin edilməyib') }}">—</span>
@endif
