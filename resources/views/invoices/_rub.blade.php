{{-- Step 3 — invoice currency -> RUB for the RUR columns: rate = D18 / D19 (AZN per invoice-currency unit / AZN per rouble). --}}
@php
    $cur = $invoice->currency;
    $failed = $errors->hasAny(['fx_source', 'fx_date', 'fx_base_azn', 'fx_target_azn']);
@endphp
<section class="card step-card" x-data="{
        source: @js(old('fx_source', $invoice->fx_source ?? 'cbar')),
        base: @js((string) old('fx_base_azn', $invoice->fx_source === 'manual' ? (float) $invoice->fx_base_azn : '')),
        rub: @js((string) old('fx_target_azn', $invoice->fx_source === 'manual' ? (float) $invoice->fx_target_azn : '')),
        editing: {{ ! $invoice->hasRub() || $failed ? 'true' : 'false' }},
        num(v) { return parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); },
        ratio() { const a = this.num(this.base), b = this.num(this.rub); return a > 0 && b > 0 ? a / b : null; },
     }">
    <header class="step-head">
        <span class="step-no">3</span>
        <div class="min-w-0">
            <h2 class="step-title">RUB məzənnəsi</h2>
            <p class="step-sub">UNIT PRICE CCL × ({{ $cur }} / RUB)</p>
        </div>
        <span @class(['badge ml-auto shrink-0', 'badge-green' => $invoice->hasRub(), 'badge-slate' => ! $invoice->hasRub()])>{{ ! $invoice->hasRub() ? 'Gözləyir' : ($invoice->fx_source === 'cbar' ? 'CBAR' : 'Əl ilə') }}</span>
    </header>

    @if($invoice->hasRub())
        <div class="step-summary">
            <div class="step-value">1 {{ $cur }} = {{ num($invoice->fx_rate, 4) }} ₽</div>
            <div class="step-meta">
                {{ $cur }} {{ rate_fmt($invoice->fx_base_azn) }} ₼ / RUB {{ rate_fmt($invoice->fx_target_azn) }} ₼
                @if($invoice->fx_date) · {{ azdate($invoice->fx_date) }}@endif
                @if($invoice->fx_bulletin_date && $invoice->fx_date && ! $invoice->fx_bulletin_date->equalTo($invoice->fx_date)) (bülleten {{ azdate($invoice->fx_bulletin_date) }})@endif
            </div>
        </div>
    @endif

    @can('projects.update')
        <form method="POST" action="{{ route('invoices.rub', $invoice) }}" x-show="editing" x-collapse {{ $invoice->hasRub() && ! $failed ? 'x-cloak' : '' }} class="step-form">
            @csrf
            <input type="hidden" name="fx_source" :value="source">
            <div class="segmented" role="radiogroup" aria-label="Məzənnə mənbəyi">
                <label :class="source === 'cbar' && 'is-on'"><input type="radio" value="cbar" x-model="source" class="sr-only"> CBAR</label>
                <label :class="source === 'manual' && 'is-on'"><input type="radio" value="manual" x-model="source" class="sr-only"> Əl ilə</label>
            </div>

            <div x-show="source === 'cbar'" class="space-y-1.5">
                <label class="field-label" for="fx-date">Məzənnə tarixi <span class="text-danger">*</span></label>
                <input id="fx-date" type="date" name="fx_date" :disabled="source !== 'cbar'" max="{{ today()->toDateString() }}"
                       value="{{ old('fx_date', ($invoice->fx_date ?? $invoice->invoice_date)?->toDateString()) }}" class="input @error('fx_date') is-invalid @enderror">
                @error('fx_date')<p class="field-error">{{ $message }}</p>@enderror
                <p class="text-[11px] text-muted">{{ $cur }} və RUB həmin günün CBAR bülletenindən.</p>
            </div>

            <div x-show="source === 'manual'" x-cloak class="space-y-1.5">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="field-label" for="fx-base">1 {{ $cur }} = ₼</label>
                        <input id="fx-base" name="fx_base_azn" x-model="base" :disabled="source !== 'manual'" inputmode="decimal" placeholder="2,0005" class="input font-mono text-right @error('fx_base_azn') is-invalid @enderror">
                    </div>
                    <div>
                        <label class="field-label" for="fx-rub">1 RUB = ₼</label>
                        <input id="fx-rub" name="fx_target_azn" x-model="rub" :disabled="source !== 'manual'" inputmode="decimal" placeholder="0,0211" class="input font-mono text-right @error('fx_target_azn') is-invalid @enderror">
                    </div>
                </div>
                @error('fx_base_azn')<p class="field-error">{{ $message }}</p>@enderror
                @error('fx_target_azn')<p class="field-error">{{ $message }}</p>@enderror
                <p class="text-[11px] text-muted" x-show="ratio()">1 {{ $cur }} = <span class="font-mono text-ink" x-text="glaustFmt.fmt(ratio(), 4)"></span> RUB</p>
            </div>

            <button class="btn btn-primary w-full"><x-icon name="check" class="size-4"/> Tətbiq et</button>
        </form>

        @if($invoice->hasRub())
            <footer class="step-foot">
                <button type="button" class="btn btn-secondary btn-sm" @click="editing = !editing"><x-icon name="pencil" class="size-3.5"/> <span x-text="editing ? 'Bağla' : 'Dəyiş'"></span></button>
                <span class="text-[11px] text-faint ml-auto">{{ azdate($invoice->fx_updated_at, true) }}</span>
                <form method="POST" action="{{ route('invoices.rub.clear', $invoice) }}" data-confirm="RUB çevirməsi silinsin?" data-confirm-action="Sil">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-icon btn-sm text-danger hover:!bg-danger-soft" aria-label="Çevirməni sil" title="Sil"><x-icon name="trash" class="size-4"/></button>
                </form>
            </footer>
        @endif
    @endcan
</section>
