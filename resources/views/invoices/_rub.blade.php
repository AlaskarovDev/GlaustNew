{{--
    Step 3 — EUR -> RUB for the RUR columns, on the date our invoice will be issued. Laid out like the
    company's sheet: CB EUR / CB RUB (CBAR, AZN per unit) and Proq EUR / Proq RUB (forecast, typed in);
    rate = EUR / RUB of the chosen source.
--}}
@php
    $cur = $invoice->currency;
    $failed = $errors->hasAny(['fx_source', 'fx_date', 'fx_base_azn', 'fx_target_azn']);
    $actual = app(\App\Support\Invoices\RubConverter::class)->actualFor($invoice);
    $today = today()->toDateString();
    $date = old('fx_date', $invoice->fx_date?->toDateString() ?? $today);
    $isForecast = $invoice->fx_source === 'forecast';
    $r = fn ($v) => $v === null ? '—' : rate_fmt($v);
@endphp
<section class="card step-card" x-data="{
        source: @js(old('fx_source', $invoice->fx_source ?? 'cbar')),
        date: @js($date),
        today: @js($today),
        base: @js((string) old('fx_base_azn', $isForecast ? rate_fmt($invoice->fx_base_azn) : '')),
        rub: @js((string) old('fx_target_azn', $isForecast ? rate_fmt($invoice->fx_target_azn) : '')),
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
        ratio() { const a = this.num(this.base), b = this.num(this.rub); return a > 0 && b > 0 ? a / b : null; },
        canApply() { return this.source === 'cbar' ? !this.future() && !!this.cbar : !!this.ratio(); },
        dmy() { const [y, m, d] = (this.date || '').split('-'); return d ? d + '.' + m + '.' + y : '—'; },
        rf: (v) => glaustFmt.fmtRate(v),
        f4: (v) => glaustFmt.fmt(v, 4),
     }" x-init="load()">
    <header class="step-head">
        <span class="step-no">3</span>
        <div class="min-w-0">
            <h2 class="step-title">{{ $cur }} → RUB konvertasiya</h2>
            <p class="step-sub">Fakturanın kəsiləcəyi tarixə görə</p>
        </div>
        @if($invoice->hasRub())
            <span @class(['badge ml-auto shrink-0', 'badge-green' => ! $isForecast, 'badge-amber' => $isForecast])>{{ \App\Support\Invoices\RubConverter::SOURCES[$invoice->fx_source] }}</span>
        @else
            <span class="badge badge-slate ml-auto shrink-0">Gözləyir</span>
        @endif
    </header>

    @if($invoice->hasRub())
        @php
            $cb = $isForecast ? $actual : ['base' => $invoice->fx_base_azn, 'rub' => $invoice->fx_target_azn];
            $d = azdate($invoice->fx_date);
        @endphp
        <div class="step-summary">
            <div class="step-value">1 {{ $cur }} = {{ num($invoice->fx_rate, 4) }} ₽</div>
            <table class="rate-table mt-2">
                <tr @class(['is-used' => ! $isForecast])><td>{{ $d }}</td><td>CB {{ $cur }}</td><td>{{ $cb ? $r($cb['base']) : 'dərc olunmayıb' }}</td></tr>
                <tr @class(['is-used' => ! $isForecast])><td>{{ $d }}</td><td>CB RUB</td><td>{{ $cb ? $r($cb['rub']) : 'dərc olunmayıb' }}</td></tr>
                @if($isForecast)
                    <tr class="gap"><td colspan="3"></td></tr>
                    <tr class="is-used"><td>{{ $d }}</td><td>Proq {{ $cur }}</td><td>{{ $r($invoice->fx_base_azn) }}</td></tr>
                    <tr class="is-used"><td>{{ $d }}</td><td>Proq RUB</td><td>{{ $r($invoice->fx_target_azn) }}</td></tr>
                @endif
            </table>
            @if($isForecast && $actual)
                @php $diff = ($actual['rate'] / (float) $invoice->fx_rate - 1) * 100; @endphp
                <div class="step-meta">CBAR ilə: 1 {{ $cur }} = {{ num($actual['rate'], 4) }} ₽
                    <span @class(['font-mono', 'text-danger' => $diff > 0, 'text-success' => $diff <= 0])>({{ $diff >= 0 ? '+' : '' }}{{ num($diff, 2) }}%)</span></div>
            @endif
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

            <table class="rate-table">
                <tr :class="source === 'cbar' && 'is-used'">
                    <td x-text="dmy()"></td><td>CB {{ $cur }}</td>
                    <td><span x-show="cbar" x-text="cbar && rf(cbar.base)"></span><span x-show="!cbar" class="text-faint" x-text="loading ? '…' : '—'"></span></td>
                </tr>
                <tr :class="source === 'cbar' && 'is-used'">
                    <td x-text="dmy()"></td><td>CB RUB</td>
                    <td><span x-show="cbar" x-text="cbar && rf(cbar.rub)"></span><span x-show="!cbar" class="text-faint" x-text="loading ? '…' : '—'"></span></td>
                </tr>
                <tr class="gap"><td colspan="3"></td></tr>
                <tr :class="source === 'forecast' && 'is-used'">
                    <td x-text="dmy()"></td><td><label for="fx-base">Proq {{ $cur }}</label></td>
                    <td><input id="fx-base" name="fx_base_azn" x-model="base" :disabled="source !== 'forecast'" @focus="source = 'forecast'" inputmode="decimal" placeholder="2,0005" class="input !h-8 font-mono text-right @error('fx_base_azn') is-invalid @enderror"></td>
                </tr>
                <tr :class="source === 'forecast' && 'is-used'">
                    <td x-text="dmy()"></td><td><label for="fx-rub">Proq RUB</label></td>
                    <td><input id="fx-rub" name="fx_target_azn" x-model="rub" :disabled="source !== 'forecast'" inputmode="decimal" placeholder="0,02110" class="input !h-8 font-mono text-right @error('fx_target_azn') is-invalid @enderror"></td>
                </tr>
            </table>
            @error('fx_base_azn')<p class="field-error">{{ $message }}</p>@enderror
            @error('fx_target_azn')<p class="field-error">{{ $message }}</p>@enderror
            <p class="text-[11px] text-muted -mt-2">Kurslar: 1 vahid = ₼. Proqnoz seçilibsə RUR sütunları proqnoz üzərindən hesablanır.</p>

            <template x-if="source === 'cbar' && future()"><p class="text-xs text-saffron">Gələcək tarix üçün CBAR kursu hələ yoxdur — proqnoz daxil edin.</p></template>
            <template x-if="source === 'cbar' && error && !future()"><p class="text-xs text-danger" x-text="error"></p></template>

            <div class="flex items-center justify-between rounded-lg bg-surface-2 px-3 py-2 text-sm">
                <span class="text-muted">1 {{ $cur }} =</span>
                <span class="font-mono font-semibold" x-text="(source === 'cbar' ? (cbar ? f4(cbar.rate) : '—') : (ratio() ? f4(ratio()) : '—')) + ' ₽'"></span>
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
