@props(['icon' => 'inbox', 'title' => 'Hələ heç nə yoxdur', 'text' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center text-center px-6 py-14']) }}>
    <div class="relative mb-5">
        <div class="absolute inset-0 -m-3 rounded-[22px] bg-brand-soft/60 rotate-6"></div>
        <div class="relative grid place-items-center size-16 rounded-2xl bg-surface border border-line text-brand shadow-[var(--shadow-card)]">
            <x-icon :name="$icon" class="size-7" :stroke="1.5"/>
        </div>
    </div>
    <h3 class="text-base font-semibold text-ink">{{ $title }}</h3>
    @if($text)
        <p class="mt-1.5 max-w-sm text-sm text-muted">{{ $text }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
