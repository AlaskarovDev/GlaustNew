{{-- Fakturasız: the invoice's money in manat at the forecast rates of step 3 (Proq EUR / Proq RUB) — shown only, nothing is recomputed. --}}
@php
    $base = $invoice->fx_base_azn ? (float) $invoice->fx_base_azn : null;     // AZN per 1 invoice currency
    $target = $invoice->fx_target_azn ? (float) $invoice->fx_target_azn : null; // AZN per 1 sale currency
    $saleCur = $invoice->saleCurrency();
    $rateOf = fn (?string $c) => match (true) {
        $c === null => null,
        $c === 'AZN' => 1.0,
        $c === $invoice->currency => $base,
        $c === $saleCur => $target,
        default => null,
    };
    $rows = [];
    if ($base) {
        $rows[] = [__('Faktura (Total)'), (float) $invoice->total, $invoice->currency, $base, 'out'];
        if ($invoice->hasLogistics() && ($lr = $rateOf($invoice->logistics_currency)) !== null) {
            $rows[] = [__('Logistika xərci'), (float) $invoice->logistics_amount, $invoice->logistics_currency, $lr, 'out'];
        }
        if ($invoice->hasCommission()) {
            $rows[] = [__('Komissiya'), (float) $invoice->commission_total, $invoice->currency, $base, 'info'];
        }
    }
    if ($target && $invoice->final_amount !== null) {
        $rows[] = [__('Yekun məbləğ'), (float) $invoice->final_amount, $saleCur, $target, 'in'];
    }
    $in = collect($rows)->where(4, 'in')->sum(fn ($r) => $r[1] * $r[3]);
    $out = collect($rows)->where(4, 'out')->sum(fn ($r) => $r[1] * $r[3]);
    $complete = $base && $target && $invoice->final_amount !== null;
@endphp
<section class="card overflow-hidden mb-6">
    <header class="px-5 py-4 border-b border-line flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <h2 class="text-base font-semibold">{{ __('Proqnoz kurslarla manatda') }}</h2>
            <p class="text-xs text-muted">{{ __('Məbləğlər 3-cü addımdakı proqnoz kurslarla AZN-ə çevrilir') }}@if($invoice->fx_date) · {{ azdate($invoice->fx_date) }}@endif</p>
        </div>
        @if($base || $target)
            <div class="text-xs font-mono text-muted">@if($base)1 {{ $invoice->currency }} = {{ rate_fmt($base) }} ₼@endif @if($target) · 1 {{ $saleCur }} = {{ rate_fmt($target) }} ₼@endif</div>
        @endif
    </header>
    @if(! $base && ! $target)
        <p class="px-5 py-5 text-sm text-muted">{{ __('3-cü addımda proqnoz (və ya CBAR) kurslarını daxil edəndən sonra məbləğlər manatla burada göstəriləcək.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="table-g table-stack text-sm">
                <thead><tr><th>{{ __('Məbləğ') }}</th><th class="!text-right">{{ __('Valyutada') }}</th><th class="!text-right">{{ __('Kurs') }}</th><th class="!text-right">AZN</th></tr></thead>
                <tbody>
                @foreach($rows as [$label, $amount, $cur, $rate, $kind])
                    <tr>
                        <td data-label="{{ __('Məbləğ') }}" @class(['font-medium', 'text-muted' => $kind === 'info'])>{{ $label }}@if($kind === 'info') <span class="text-[11px] text-faint">({{ __('məlumat üçün') }})</span>@endif</td>
                        <td data-label="{{ __('Valyutada') }}" class="num">{{ money($amount, $cur) }}</td>
                        <td data-label="{{ __('Kurs') }}" class="num text-muted">{{ rate_fmt($rate) }}</td>
                        <td data-label="AZN" @class(['num font-semibold', 'text-success' => $kind === 'in', 'text-muted' => $kind === 'info'])>{{ money($amount * $rate) }}</td>
                    </tr>
                @endforeach
                </tbody>
                @if($complete)
                    <tfoot><tr class="bg-surface-2 font-semibold">
                        <td class="px-4 py-3" colspan="3">{{ __('Yekun − faktura − logistika') }}</td>
                        <td @class(['px-4 py-3 text-right font-mono', 'text-success' => $in - $out > 0, 'text-danger' => $in - $out < 0])>{{ ($in - $out < 0 ? '−' : '').money(abs($in - $out)) }}</td>
                    </tr></tfoot>
                @endif
            </table>
        </div>
        @if($invoice->final_amount === null)
            <p class="px-5 py-3 border-t border-line text-xs text-muted">{{ __('Yekun məbləği 0-cı addımda yazandan sonra o da manatla göstəriləcək.') }}</p>
        @endif
    @endif
</section>
