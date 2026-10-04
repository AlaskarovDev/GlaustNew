{{-- Step 2 — Mədaxillər: the buyer pays us. Each payment is an incoming bank movement linked to the deal. --}}
@php
    $payments = $deal->payments;
    $expected = $deal->salesDocuments->where('kind', 'proforma')->groupBy('currency')->map(fn ($g) => $g->sum(fn ($d) => $d->grandTotal()));
    $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->name.' · '.$a->bank_name, 'currency' => $a->currency])->values();
    $defaultCurrency = old('currency', $expected->keys()->first() ?? $accounts->first()?->currency ?? 'AZN');
@endphp
<div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
    @forelse($expected as $cur => $sum)
        @php $got = (float) ($received[$cur] ?? 0); $left = round($sum - $got, 2); @endphp
        <div class="card p-4" style="--i:0"><div class="text-xs text-muted">{{ __('Gözlənilən (proforma)') }}</div><div class="text-xl font-semibold font-mono">{{ money($sum, $cur) }}</div></div>
        <div class="card p-4" style="--i:1"><div class="text-xs text-muted">{{ __('Daxil olub') }}</div><div class="text-xl font-semibold font-mono text-success">{{ money($got, $cur) }}</div>
            <div class="mt-2 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full bg-success rounded-full" style="width: {{ $sum > 0 ? min(100, round($got / $sum * 100)) : 0 }}%"></div></div></div>
        <div class="card p-4" style="--i:2"><div class="text-xs text-muted">{{ __('Qalıq') }}</div><div @class(['text-xl font-semibold font-mono', 'text-saffron' => $left > 0, 'text-success' => $left <= 0])>{{ money(max($left, 0), $cur) }}</div>
            @if($left < 0)<div class="text-[11px] text-muted">Artıq ödəniş: {{ money(-$left, $cur) }}</div>@endif</div>
    @empty
        <div class="card p-4 sm:col-span-3" style="--i:0"><div class="text-xs text-muted">{{ __('Gözlənilən') }}</div><div class="text-sm text-muted mt-1">{{ __('Proforma faktura hələ yoxdur — «Fakturalar» addımını tamamlayın.') }}</div></div>
    @endforelse
    <div class="card p-4" style="--i:3"><div class="text-xs text-muted">{{ __('AZN ekvivalenti (bank kursu)') }}</div><div class="text-xl font-semibold font-mono">{{ money($payments->sum('amount_azn')) }}</div>
        @php $fx = round($payments->sum('amount_azn') - $payments->sum('cbar_amount_azn'), 2); @endphp
        <div class="text-[11px] {{ $fx < 0 ? 'text-danger' : 'text-muted' }}">CBAR ilə: {{ money($payments->sum('cbar_amount_azn')) }} · fərq {{ $fx > 0 ? '+' : '' }}{{ money($fx) }}</div></div>
</div>

<h3 class="text-xs font-semibold uppercase tracking-wide text-muted mb-3">{{ __('Alıcıdan gələn ödənişlər') }}</h3>
@include('deals._payment-form', ['dir' => 'in'])
@include('deals._payment-list', ['dir' => 'in'])

<h3 class="text-xs font-semibold uppercase tracking-wide text-muted mb-3 mt-8">{{ __('Satıcıya ödənişlər') }}</h3>
@include('deals._supplier-payment-form')
@include('deals._supplier-payment-list')
