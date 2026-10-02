@props(['name', 'url', 'value' => null, 'display' => null, 'label' => null, 'placeholder' => 'Seçin…', 'required' => false, 'hint' => null, 'wrapper' => ''])
@php
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $value = old($errorKey, $value);
@endphp
<x-field :label="$label" :name="$name" :hint="$hint" :required="$required" :class="$wrapper" :for="$name.'-button'">
    <div x-data="combobox({ name: @js($name), url: @js($url), value: @js($value), label: @js($display) })" class="relative" @keydown.escape="open = false" @click.outside="open = false" {{ $attributes }}>
        <input type="hidden" name="{{ $name }}" :value="value">
        <div @class(['input flex items-center gap-1 !px-0', 'is-invalid' => $errors->has($errorKey)])>
            <button type="button" id="{{ $name }}-button" @click="open = !open" :aria-expanded="open" aria-haspopup="listbox"
                    class="flex-1 min-w-0 h-full flex items-center gap-2 pl-3 text-left outline-none">
                <span class="flex-1 truncate" :class="!label && 'text-faint'" x-text="label || @js($placeholder)">{{ $display ?? $placeholder }}</span>
                <x-icon name="chevron-down" class="size-4 text-muted shrink-0"/>
            </button>
            @unless($required)
                <button type="button" x-show="value" x-cloak @click="pick(null)" class="grid place-items-center size-7 mr-1.5 rounded-md text-faint hover:text-ink hover:bg-surface-2" aria-label="Təmizlə"><x-icon name="x" class="size-4"/></button>
            @else
                <span class="w-2"></span>
            @endunless
        </div>
        <div x-cloak x-show="open" x-transition.origin.top class="absolute z-40 mt-1.5 w-full min-w-[260px] card !shadow-[var(--shadow-pop)] overflow-hidden">
            <div class="p-2 border-b border-line">
                <input x-ref="q" x-model="query" @input="fetch()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                       @keydown.enter.prevent="items[active] && pick(items[active])" class="input !h-9" placeholder="Axtar…" aria-label="Axtar">
            </div>
            <ul class="max-h-64 overflow-y-auto p-1" role="listbox">
                <template x-for="(item, i) in items" :key="item.id">
                    <li role="option" :aria-selected="item.id == value" @click="pick(item)" @mouseenter="active = i"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer text-sm" :class="active === i ? 'bg-brand-soft text-brand-ink' : 'text-ink-2'">
                        <span class="flex-1 min-w-0"><span class="block truncate font-medium" x-text="item.label"></span><span class="block truncate text-xs text-muted" x-text="item.meta || ''"></span></span>
                        <x-icon name="check" class="size-4 text-brand" x-show="item.id == value"/>
                    </li>
                </template>
                <li x-show="loading" class="px-3 py-3 text-sm text-muted">Yüklənir…</li>
                <li x-show="!loading && !items.length" class="px-3 py-3 text-sm text-muted">Nəticə yoxdur</li>
            </ul>
            @isset($footer)
                <div class="border-t border-line p-1.5">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</x-field>
