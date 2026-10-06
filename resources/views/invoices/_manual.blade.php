{{-- Step 2 of a manual entry: the seller's total and our total with commission and other amounts (their difference is the commission column). --}}
@php $failed = $errors->hasAny(['seller_amount', 'sale_base', 'invoice_date', 'number']); @endphp
<section class="card step-card" x-data="{
        seller: @js((string) old('seller_amount', num($invoice->total))),
        base: @js((string) old('sale_base', num($invoice->saleBase()))),
        editing: {{ $failed ? 'true' : 'false' }},
        num(v) { return parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')); },
        diff() { const s = this.num(this.seller), b = this.num(this.base); return isNaN(s) || isNaN(b) ? null : b - s; },
        fmt: (v) => glaustFmt.fmt(v, 2),
     }">
    <header class="step-head">
        <span class="step-no">2</span>
        <div class="min-w-0">
            <h2 class="step-title">{{ __('Məbləğlər (fakturasız)') }}</h2>
            <p class="step-sub">{{ __('Satıcının məbləği və komissiya daxil yekun') }}</p>
        </div>
        <span class="badge badge-green ml-auto shrink-0">{{ __('Daxil edilib') }}</span>
    </header>

    <div class="step-summary space-y-1">
        <div class="flex justify-between gap-3 text-sm"><span class="text-muted">{{ __('Satıcının faktura məbləği') }}</span><span class="font-mono">{{ money($invoice->total, $invoice->currency) }}</span></div>
        <div class="flex justify-between gap-3 text-sm"><span class="text-muted">{{ __('Komissiya və digər məbləğlər') }}</span><span class="font-mono">{{ money($invoice->commission_total, $invoice->currency) }}</span></div>
        <div class="flex justify-between gap-3 border-t border-line pt-1"><span class="text-muted text-sm">{{ __('Yekun məbləğ') }}</span><span class="step-value !text-lg">{{ money($invoice->saleBase(), $invoice->currency) }}</span></div>
    </div>

    @if(auth()->user()->can('projects.update') && ! $invoice->isLocked())
        <form method="POST" action="{{ route('invoices.manual.update', $invoice) }}" x-show="editing" x-collapse {{ $failed ? '' : 'x-cloak' }} class="step-form">
            @csrf @method('PUT')
            <div class="grid grid-cols-2 gap-2">
                <x-input name="number" :label="__('Nömrə')" :value="$invoice->number"/>
                <x-input name="invoice_date" type="date" :label="__('Tarix')" :value="$invoice->invoice_date" required :max="today()->format('Y-m-d')"/>
            </div>
            <div class="space-y-1.5">
                <label class="field-label" for="mn-seller">{{ __('Satıcının faktura məbləği') }} ({{ $invoice->currency }}) <span class="text-danger">*</span></label>
                <input id="mn-seller" name="seller_amount" x-model="seller" inputmode="decimal" class="input font-mono text-right @error('seller_amount') is-invalid @enderror">
                @error('seller_amount')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-1.5">
                <label class="field-label" for="mn-base">{{ __('Yekun məbləğ (komissiya və digər məbləğlər daxil)') }} <span class="text-danger">*</span></label>
                <input id="mn-base" name="sale_base" x-model="base" inputmode="decimal" class="input font-mono text-right @error('sale_base') is-invalid @enderror">
                @error('sale_base')<p class="field-error">{{ $message }}</p>@enderror
                <p class="text-[11px] text-muted" x-show="diff() !== null">{{ __('Komissiya və digər məbləğlər') }}: <span class="font-mono text-ink" x-text="fmt(diff()) + ' {{ $invoice->currency }}'"></span></p>
            </div>
            <button class="btn btn-primary w-full"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
        </form>
        <footer class="step-foot">
            <button type="button" class="btn btn-secondary btn-sm" @click="editing = !editing"><x-icon name="pencil" class="size-3.5"/> <span x-text="editing ? {{ \Illuminate\Support\Js::from(__('Bağla')) }} : {{ \Illuminate\Support\Js::from(__('Dəyiş')) }}"></span></button>
            <span class="text-[11px] text-faint ml-auto">{{ azdate($invoice->commission_updated_at, true) }}</span>
        </footer>
    @endif
</section>
