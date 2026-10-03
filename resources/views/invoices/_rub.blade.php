{{-- Step 3 — EUR -> RUB for the RUR columns, on the date our invoice will be issued: CBAR rate of that day, or a forecast. --}}
@php
    $cur = $invoice->currency;
    $failed = $errors->hasAny(['fx_source', 'fx_date', 'fx_forecast']);
    $actual = app(\App\Support\Invoices\RubConverter::class)->actualFor($invoice);
    $today = today()->toDateString();
    $date = old('fx_date', $invoice->fx_date?->toDateString() ?? $today);
@endphp
<section class="card step-card" x-data="{
        source: @js(old('fx_source', $invoice->fx_source ?? 'cbar')),
        date: @js($date),
        today: @js($today),
        forecast: @js((string) old('fx_forecast', $invoice->fx_source === 'forecast' ? num($invoice->fx_rate, 4) : '')),
        editing: {{ ! $invoice->hasRub() || $failed ? 'true' : 'false' }},
        cbar: null, loading: false, error: '',
        future() { return this.date > this.today; },
        async load() {
            this.cbar = null; this.error = '';
            if (!this.date) return;
            if (this.future()) { this.source = 'forecast'; return; }
            this.loading = true;
            try {
                const d = await glaustApi('/ajax/cross-rate?from={{ $cur }}&date=' + encodeURIComponent(this.date));
                d.ok ? (this.cbar = d) : (this.error = d.message);
            } catch (e) { this.error = e.message; }
            this.loading = false;
        },
        num(v) { return parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); },
        canApply() { return this.source === 'cbar' ? !this.future() && !!this.cbar : this.num(this.forecast) > 0; },
        f4: (v) => glaustFmt.fmt(v, 4),
     }" x-init="load()">
    <header class="step-head">
        <span class="step-no">3</span>
        <div class="min-w-0">
            <h2 class="step-title">{{ $cur }} → RUB konvertasiya</h2>
            <p class="step-sub">Fakturanın kəsiləcəyi tarixə görə</p>
        </div>
        @if($invoice->hasRub())
            <span @class(['badge ml-auto shrink-0', 'badge-green' => $invoice->fx_source === 'cbar', 'badge-amber' => $invoice->fx_source === 'forecast'])>{{ \App\Support\Invoices\RubConverter::SOURCES[$invoice->fx_source] }}</span>
        @else
            <span class="badge badge-slate ml-auto shrink-0">Gözləyir</span>
        @endif
    </header>

    @if($invoice->hasRub())
        <div class="step-summary">
            <div class="step-value">1 {{ $cur }} = {{ num($invoice->fx_rate, 4) }} ₽</div>
            <div class="step-meta">
                Faktura tarixi: <span class="text-ink">{{ azdate($invoice->fx_date) }}</span>
                @if($invoice->fx_source === 'cbar')
                    <br>{{ $cur }} {{ rate_fmt($invoice->fx_base_azn) }} ₼ / RUB {{ rate_fmt($invoice->fx_target_azn) }} ₼
                    @if($invoice->fx_bulletin_date && ! $invoice->fx_bulletin_date->equalTo($invoice->fx_date)) · bülleten {{ azdate($invoice->fx_bulletin_date) }}@endif
                @elseif($actual)
                    @php $diff = ($actual / (float) $invoice->fx_rate - 1) * 100; @endphp
                    <br>CBAR faktiki: <span class="font-mono text-ink">{{ num($actual, 4) }}</span>
                    <span @class(['font-mono', 'text-danger' => $diff > 0, 'text-success' => $diff <= 0])>({{ $diff >= 0 ? '+' : '' }}{{ num($diff, 2) }}%)</span>
                @else
                    <br>CBAR kursu bu tarix üçün hələ dərc olunmayıb.
                @endif
            </div>
        </div>
    @endif

    @can('projects.update')
        <form method="POST" action="{{ route('invoices.rub', $invoice) }}" x-show="editing" x-collapse {{ $invoice->hasRub() && ! $failed ? 'x-cloak' : '' }} class="step-form">
            @csrf
            <input type="hidden" name="fx_source" :value="source">
            <div class="space-y-1.5">
                <label class="field-label" for="fx-date">Fakturanın kəsiləcəyi tarix <span class="text-danger">*</span></label>
                <input id="fx-date" type="date" name="fx_date" x-model="date" @change="load()" class="input @error('fx_date') is-invalid @enderror">
                @error('fx_date')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="segmented" role="radiogroup" aria-label="Kurs mənbəyi">
                <label :class="source === 'cbar' && 'is-on'"><input type="radio" value="cbar" x-model="source" class="sr-only"> CBAR kursu</label>
                <label :class="source === 'forecast' && 'is-on'"><input type="radio" value="forecast" x-model="source" class="sr-only"> Proqnoz</label>
            </div>

            {{-- CBAR rate of the chosen day --}}
            <div x-show="source === 'cbar'" class="rounded-xl border border-line px-4 py-3 text-sm">
                <template x-if="future()"><p class="text-saffron text-xs">Gələcək tarix üçün CBAR kursu hələ yoxdur — <button type="button" class="underline font-medium" @click="source = 'forecast'">proqnoz daxil edin</button>.</p></template>
                <template x-if="!future() && loading"><p class="text-muted text-xs">CBAR kursu yüklənir…</p></template>
                <template x-if="!future() && error"><p class="text-danger text-xs" x-text="error"></p></template>
                <template x-if="!future() && cbar">
                    <div>
                        <div class="font-mono font-semibold">1 {{ $cur }} = <span x-text="f4(cbar.rate)"></span> ₽</div>
                        <div class="text-[11px] text-muted mt-0.5">{{ $cur }} <span x-text="f4(cbar.base)"></span> ₼ / RUB <span x-text="f4(cbar.rub)"></span> ₼ · CBAR</div>
                    </div>
                </template>
            </div>

            {{-- Forecast: its own section; the RUR columns are computed from it --}}
            <div x-show="source === 'forecast'" x-cloak class="rounded-xl border border-dashed border-saffron/60 bg-saffron-soft/30 px-4 py-3 space-y-2">
                <div class="flex items-center gap-2 text-[13px] font-semibold"><x-icon name="trending-up" class="size-4 text-saffron"/> Proqnoz kursu</div>
                <label class="sr-only" for="fx-forecast">Proqnoz: 1 {{ $cur }} = RUB</label>
                <div class="flex items-center gap-2">
                    <span class="font-mono text-sm text-muted shrink-0">1 {{ $cur }} =</span>
                    <input id="fx-forecast" name="fx_forecast" x-model="forecast" :disabled="source !== 'forecast'" inputmode="decimal" placeholder="94,8104" class="input flex-1 min-w-0 font-mono text-right @error('fx_forecast') is-invalid @enderror">
                    <span class="font-mono text-sm text-muted shrink-0">₽</span>
                </div>
                @error('fx_forecast')<p class="field-error">{{ $message }}</p>@enderror
                <p class="text-[11px] text-muted">RUR sütunları bu proqnoz üzərindən hesablanacaq.
                    <template x-if="cbar"><span>Seçilən günün CBAR kursu: <button type="button" class="font-mono underline" @click="forecast = f4(cbar.rate)" x-text="f4(cbar.rate)"></button></span></template>
                </p>
            </div>

            <button class="btn btn-primary w-full" :disabled="!canApply()"><x-icon name="check" class="size-4"/> Tətbiq et</button>
        </form>

        @if($invoice->hasRub())
            <footer class="step-foot">
                <button type="button" class="btn btn-secondary btn-sm" @click="editing = !editing"><x-icon name="pencil" class="size-3.5"/> <span x-text="editing ? 'Bağla' : 'Dəyiş'"></span></button>
                <span class="text-[11px] text-faint ml-auto">{{ azdate($invoice->fx_updated_at, true) }}</span>
                <form method="POST" action="{{ route('invoices.rub.clear', $invoice) }}" data-confirm="Konvertasiya silinsin?" data-confirm-action="Sil">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost btn-icon btn-sm text-danger hover:!bg-danger-soft" aria-label="Konvertasiyanı sil" title="Sil"><x-icon name="trash" class="size-4"/></button>
                </form>
            </footer>
        @endif
    @endcan
</section>
