{{-- The seller invoice's own terms, both optional: freight billed on the invoice (NAVLUN) and a discount in percent of
     the goods — spread over the lines by their Total (FreightDiscount). Not our logistics cost: it is what we owe the seller. --}}
@php
    $goods = \App\Support\Invoices\FreightDiscount::goods($invoice);
    $hasTerms = $invoice->freight_amount !== null || $invoice->discount_percent !== null;
    $failed = $errors->has('freight_amount') || $errors->has('discount_percent');
    $pctLabel = fn ($v) => rtrim(rtrim(num($v, 4), '0'), ',');
    $canEdit = auth()->user()->can('projects.update') && ! $invoice->isLocked() && $invoice->status !== 'cancelled';
@endphp
@if($hasTerms || $canEdit)
<section class="card mb-6" x-data="{
        open: {{ $hasTerms || $failed ? 'true' : 'false' }},
        freight: @js((string) old('freight_amount', $invoice->freight_amount !== null ? num($invoice->freight_amount) : '')),
        pct: @js((string) old('discount_percent', $invoice->discount_percent !== null ? $pctLabel($invoice->discount_percent) : '')),
        goods: {{ $goods }},
        num(v) { const n = parseFloat(String(v ?? '').replace(/[\s %]/g, '').replace(',', '.')); return isNaN(n) ? 0 : n; },
        disc() { return Math.round(this.goods * this.num(this.pct)) / 100; },
        total() { return Math.round((this.goods - this.disc() + this.num(this.freight)) * 100) / 100; },
        fmt: (v) => glaustFmt.fmt(v, 2),
     }">
    <header class="flex flex-wrap items-center gap-3 px-5 py-4">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold">{{ __('Faktura daxili navlun və endirim') }} <span class="text-xs font-normal text-muted">{{ __('· məcburi deyil') }}</span></h2>
            <p class="text-xs text-muted">{{ __('Satıcının fakturasındakı navlun (bizim logistika xərcimizdən ayrıdır) və endirim faizi — məhsullar arasında Total-a görə proporsional bölünür.') }}</p>
        </div>
        @if($hasTerms)
            <div class="flex flex-wrap gap-1.5 ml-auto">
                @if($invoice->discount_percent !== null)<span class="badge badge-green">{{ __('Endirim') }} {{ $pctLabel($invoice->discount_percent) }}% = −{{ money($invoice->discount_amount, $invoice->currency) }}</span>@endif
                @if($invoice->freight_amount !== null)<span class="badge badge-amber">{{ __('Navlun') }} +{{ money($invoice->freight_amount, $invoice->currency) }}</span>@endif
            </div>
        @endif
        @if($canEdit)
            <button type="button" @class(['btn btn-secondary btn-sm', 'ml-auto' => ! $hasTerms]) @click="open = !open"><span x-text="open ? {{ \Illuminate\Support\Js::from(__('Bağla')) }} : {{ \Illuminate\Support\Js::from(__('Əlavə et')) }}"></span></button>
        @endif
    </header>
    @if($canEdit)
        <form method="POST" action="{{ route('invoices.terms', $invoice) }}" x-show="open" x-collapse @unless($hasTerms || $failed) x-cloak @endunless class="px-5 pb-5 border-t border-line pt-4">
            @csrf
            <div class="grid sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.4fr_auto] gap-4 items-end">
                <div class="space-y-1.5">
                    <label class="field-label" for="tr-pct">{{ __('Endirim faizi') }}</label>
                    <div class="relative">
                        <input id="tr-pct" name="discount_percent" x-model="pct" inputmode="decimal" placeholder="0" autocomplete="off" class="input font-mono text-right !pr-9 @error('discount_percent') is-invalid @enderror">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted font-mono pointer-events-none">%</span>
                    </div>
                    @error('discount_percent')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="space-y-1.5">
                    <label class="field-label" for="tr-freight">{{ __('Navlun') }} ({{ $invoice->currency }})</label>
                    <input id="tr-freight" name="freight_amount" x-model="freight" inputmode="decimal" placeholder="0" autocomplete="off" class="input font-mono text-right @error('freight_amount') is-invalid @enderror">
                    @error('freight_amount')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <dl class="text-xs space-y-1 rounded-lg bg-surface-2 border border-line px-3 py-2">
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('Mallar (satıcının rəqəmi)') }}</dt><dd class="font-mono" x-text="fmt(goods)"></dd></div>
                    <div class="flex justify-between gap-3" x-show="num(pct) > 0"><dt class="text-muted">{{ __('Endirim') }}</dt><dd class="font-mono text-success" x-text="'−' + fmt(disc())"></dd></div>
                    <div class="flex justify-between gap-3" x-show="num(freight) > 0"><dt class="text-muted">{{ __('Navlun') }}</dt><dd class="font-mono" x-text="'+' + fmt(num(freight))"></dd></div>
                    <div class="flex justify-between gap-3 font-semibold text-ink"><dt>{{ __('Fakturanın cəmi') }}</dt><dd class="font-mono" x-text="fmt(total()) + ' {{ $invoice->currency }}'"></dd></div>
                </dl>
                <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Tətbiq et') }}</button>
            </div>
            <p class="text-[11px] text-muted mt-3">{{ __('Hər ikisini boş qoyub «Tətbiq et» bassanız, satıcının ilkin qiymətləri bərpa olunur. Komissiya və Total-a görə bölünən logistika yeni məbləğlərə görə yenidən hesablanır.') }}</p>
        </form>
    @endif
</section>
@endif
