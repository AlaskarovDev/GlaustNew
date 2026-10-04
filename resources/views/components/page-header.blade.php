@props(['title', 'subtitle' => null, 'back' => null, 'icon' => null])
<div {{ $attributes->merge(['class' => 'mb-6 lg:mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between']) }}>
    <div class="min-w-0">
        @if($back)
            <a href="{{ $back }}" class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-ink mb-2 transition-colors">
                <x-icon name="arrow-left" class="size-4"/> {{ __('Geri') }}
            </a>
        @endif
        <div class="flex items-center gap-3">
            @if($icon)
                <span class="hidden sm:grid place-items-center size-11 shrink-0 rounded-xl bg-brand-soft text-brand"><x-icon :name="$icon" class="size-[22px]"/></span>
            @endif
            <div class="min-w-0">
                <h1 class="text-2xl lg:text-[28px] font-semibold tracking-tight text-ink break-words">{{ $title }}</h1>
                @if($subtitle)
                    <p class="mt-1 text-sm text-muted">{{ $subtitle }}</p>
                @endif
            </div>
        </div>
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 shrink-0">{{ $actions }}</div>
    @endisset
</div>
