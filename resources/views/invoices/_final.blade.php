{{-- Step 0 of a manual entry: the final figure with every cost included, as the user already has it. Stored as is; steps 1–3 are only kept beside it. --}}
@php
    $cur = $invoice->saleCurrency();
    $has = $invoice->final_amount !== null;
    $failed = $errors->has('final_amount');
@endphp
<section class="card mb-4 overflow-hidden border-brand/40" x-data="{ editing: {{ ! $has || $failed ? 'true' : 'false' }} }">
    <div class="p-5 flex flex-wrap items-center gap-4">
        <span class="step-no">0</span>
        <div class="min-w-0 flex-1">
            <h2 class="step-title">{{ __('Yekun məbləğ (bütün xərclər daxil)') }}</h2>
            <p class="step-sub">{{ __('Sizdə hazır olan son rəqəm — alıcıya bu məbləğ yazılır. Aşağıdakı logistika, komissiya və proqnoz kursları yalnız yadda saxlanılır, bu rəqəmi dəyişmir.') }}</p>
        </div>
        @if($has)
            <div class="text-right">
                <div class="text-2xl font-semibold font-mono text-brand-ink">{{ money($invoice->final_amount, $cur) }}</div>
                <div class="text-[11px] text-muted">{{ __('Faktura (Total)') }}: <span class="font-mono">{{ money($invoice->total, $invoice->currency) }}</span></div>
            </div>
        @else
            <span class="badge badge-slate">{{ __('Gözləyir') }}</span>
        @endif
    </div>

    @if(auth()->user()->can('projects.update') && ! $invoice->isLocked())
        <form method="POST" action="{{ route('invoices.final', $invoice) }}" x-show="editing" x-collapse {{ $has && ! $failed ? 'x-cloak' : '' }} class="px-5 pb-5 flex flex-wrap items-end gap-3">
            @csrf
            <x-field :label="__('Yekun məbləğ').' ('.$cur.')'" name="final_amount" required class="flex-1 min-w-[220px]">
                <input name="final_amount" value="{{ old('final_amount', $has ? num($invoice->final_amount) : '') }}" inputmode="decimal" class="input font-mono text-right @error('final_amount') is-invalid @enderror" placeholder="8 950 000,00" required>
            </x-field>
            <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
        </form>
        @if($has)
            <footer class="step-foot">
                <button type="button" class="btn btn-secondary btn-sm" @click="editing = !editing"><x-icon name="pencil" class="size-3.5"/> <span x-text="editing ? {{ \Illuminate\Support\Js::from(__('Bağla')) }} : {{ \Illuminate\Support\Js::from(__('Dəyiş')) }}"></span></button>
                <span class="ml-auto"></span>
                <form method="POST" action="{{ route('invoices.final.clear', $invoice) }}" data-confirm="{{ __('Yekun məbləğ silinsin?') }}" data-confirm-action="{{ __('Sil') }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-icon btn-sm text-danger hover:!bg-danger-soft" aria-label="{{ __('Sil') }}" title="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                </form>
            </footer>
        @endif
    @endif
</section>
