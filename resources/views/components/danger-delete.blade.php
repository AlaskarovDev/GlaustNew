{{-- Delete with everything inside: a header button opening a dialog that lists what goes and asks for the code. --}}
@props(['action', 'code', 'title', 'items' => [], 'note' => null, 'label' => null])
<div x-data="{ open: false, typed: '' }" class="contents">
    <button type="button" class="btn btn-secondary text-danger hover:!bg-danger-soft" @click="open = true; typed = ''; $nextTick(() => $refs.code?.focus())">
        <x-icon name="trash" class="size-4"/> {{ $label ?? __('Sil') }}
    </button>
    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-[80] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = false"></div>
            <form method="POST" action="{{ $action }}" class="relative w-full max-w-md card p-6 space-y-4 !shadow-[var(--shadow-pop)]" x-trap.noscroll="open">
                @csrf @method('DELETE')
                <div class="flex items-start gap-3">
                    <span class="grid place-items-center size-10 rounded-xl bg-danger-soft text-danger shrink-0"><x-icon name="trash" class="size-5"/></span>
                    <div>
                        <h2 class="text-lg font-semibold">{{ $title }}</h2>
                        <p class="text-sm text-muted">{{ __('Bu əməliyyat geri qaytarıla bilməz.') }}</p>
                    </div>
                </div>
                @php $items = array_filter($items); @endphp
                @if($items)
                    <div class="rounded-xl border border-danger/25 bg-danger-soft/40 p-3 text-sm">
                        <div class="font-medium text-danger mb-1">{{ __('Birlikdə silinəcək:') }}</div>
                        <ul class="space-y-0.5">@foreach($items as $label => $n)<li class="flex justify-between gap-3"><span>{{ $label }}</span><span class="font-mono">{{ $n }}</span></li>@endforeach</ul>
                    </div>
                @endif
                @if($note)<p class="text-xs text-muted">{{ $note }}</p>@endif
                <label class="block">
                    <span class="field-label">{{ __('Təsdiq üçün kodu yazın:') }} <b class="font-mono text-ink">{{ $code }}</b></span>
                    <input x-ref="code" name="confirm_code" x-model="typed" autocomplete="off" class="input font-mono" placeholder="{{ $code }}">
                </label>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" @click="open = false">{{ __('Bağla') }}</button>
                    <button class="btn bg-danger text-white hover:opacity-90" :disabled="typed.trim() !== @js($code)"><x-icon name="trash" class="size-4"/> {{ __('Birdəfəlik sil') }}</button>
                </div>
            </form>
        </div>
    </template>
</div>
