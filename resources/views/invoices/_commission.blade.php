{{-- Step 2 — our commission %: line Total × % (the sheet's =H*0.035, with our own rate). Independent of the logistics cost. --}}
@php
    $grand = (float) $invoice->items->sum('total');
    $failed = $errors->has('commission_rate');
@endphp
<section class="card step-card" x-data="{
        rate: @js((string) old('commission_rate', $invoice->hasCommission() ? $invoice->commissionLabel() : '')),
        grand: {{ $grand }},
        editing: {{ ! $invoice->hasCommission() || $failed ? 'true' : 'false' }},
        num(v) { return parseFloat(String(v ?? '').replace(/[\s%]/g, '').replace(',', '.')); },
        valid() { const r = this.num(this.rate); return !isNaN(r) && r >= 0 && r <= 100; },
        preview() { return this.valid() ? this.grand * this.num(this.rate) / 100 : 0; },
        fmt: (v) => glaustFmt.fmt(v, 2),
     }">
    <header class="step-head">
        <span class="step-no">2</span>
        <div class="min-w-0">
            <h2 class="step-title">{{ __('Komissiya faizi') }}</h2>
            <p class="step-sub">{{ __('Hər sətir: Total × faiz') }}</p>
        </div>
        <span @class(['badge ml-auto shrink-0', 'badge-green' => $invoice->hasCommission(), 'badge-slate' => ! $invoice->hasCommission()])>{{ $invoice->hasCommission() ? 'Tətbiq olunub' : 'Gözləyir' }}</span>
    </header>

    @if($invoice->hasCommission())
        <div class="step-summary">
            <div class="step-value">{{ $invoice->commissionLabel() }}%</div>
            <div class="step-meta">{{ __('Cəmi komissiya:') }} <span class="font-mono text-ink">{{ money($invoice->commission_total, $invoice->currency) }}</span></div>
        </div>
    @endif

    @if(auth()->user()->can('projects.update') && ! $invoice->isLocked())
        <form method="POST" action="{{ route('invoices.commission', $invoice) }}" x-show="editing" x-collapse {{ $invoice->hasCommission() && ! $failed ? 'x-cloak' : '' }} class="step-form">
            @csrf
            <div class="space-y-1.5">
                <label class="field-label" for="cm-rate">{{ __('Bizim komissiya faizimiz') }} <span class="text-danger">*</span></label>
                <div class="relative">
                    <input id="cm-rate" name="commission_rate" x-model="rate" inputmode="decimal" placeholder="3,5" autocomplete="off"
                           class="input font-mono text-right !pr-9 @error('commission_rate') is-invalid @enderror">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted font-mono pointer-events-none">%</span>
                </div>
                @error('commission_rate')<p class="field-error">{{ $message }}</p>@enderror
                <p class="text-[11px] text-muted" x-show="valid() && rate !== ''">{{ __('Cəmi komissiya:') }} <span class="font-mono text-ink" x-text="fmt(preview()) + ' {{ $invoice->currency }}'"></span></p>
            </div>
            <button class="btn btn-primary w-full" :disabled="!valid() || rate === ''"><x-icon name="check" class="size-4"/> {{ __('Tətbiq et') }}</button>
        </form>

        @if($invoice->hasCommission())
            <footer class="step-foot">
                <button type="button" class="btn btn-secondary btn-sm" @click="editing = !editing"><x-icon name="pencil" class="size-3.5"/> <span x-text="editing ? 'Bağla' : 'Dəyiş'"></span></button>
                <span class="text-[11px] text-faint ml-auto">{{ azdate($invoice->commission_updated_at, true) }}</span>
                <form method="POST" action="{{ route('invoices.commission.clear', $invoice) }}" data-confirm="{{ __('Komissiya bütün sətirlərdən silinsin?') }}" data-confirm-action="{{ __('Sil') }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-icon btn-sm text-danger hover:!bg-danger-soft" aria-label="{{ __('Komissiyanı sil') }}" title="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                </form>
            </footer>
        @endif
    @endif
</section>
