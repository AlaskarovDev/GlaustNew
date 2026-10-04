{{-- Maliyyə öhdəlikləri: who owes whom how much (money and goods), per currency, above the work tabs. --}}
@php
    $ob = \App\Support\DealObligations::for($deal);
    // Amounts per currency; a placeholder (no amount) renders as plain muted text, not as a figure.
    $amounts = function (array $byCur, string $empty = '—') {
        return $byCur
            ? e(collect($byCur)->map(fn ($v, $c) => money($v, $c))->implode(' · '))
            : '<span class="ob-none">'.e($empty).'</span>';
    };
    $pct = function (array $part, array $whole) {
        $w = array_sum($whole);
        return $w > 0 ? min(100, round(array_sum(array_intersect_key($part, $whole)) / $w * 100)) : 0;
    };
@endphp
<section class="mb-6" aria-labelledby="ob-title">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-3">
        <h2 id="ob-title" class="text-sm font-semibold text-ink-2 flex items-center gap-2"><x-icon name="transfer" class="size-4 text-muted"/> {{ __('Maliyyə öhdəlikləri') }}</h2>
        <div class="flex flex-wrap gap-2 text-sm">
            <span class="ob-pill is-out"><x-icon name="arrow-up-right" class="size-4"/> {{ __('Ödəməliyik:') }} <b class="font-mono">{!! $amounts($ob['payable'], '0') !!}</b></span>
            <span class="ob-pill is-in"><x-icon name="arrow-down-left" class="size-4"/> {{ __('Bizə ödəniləcək:') }} <b class="font-mono">{!! $amounts($ob['receivable'], '0') !!}</b></span>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        {{-- Buyer --}}
        <article class="card p-4 space-y-3">
            <header class="flex items-start justify-between gap-2">
                <div class="min-w-0"><div class="text-xs text-muted">{{ __('Alıcı') }}</div><div class="font-semibold truncate">{{ $ob['buyer']['name'] ?? '—' }}</div></div>
                <span class="badge badge-teal shrink-0">{{ __('bizə ödəyir') }}</span>
            </header>
            <dl class="ob-rows">
                <div><dt>{{ __('Bizə ödəməlidir') }}</dt><dd class="text-success">{!! $amounts($ob['buyer']['due'], $ob['buyer']['billed'] ? __('tam ödəyib') : 'proforma yoxdur') !!}</dd></div>
                <div><dt>{{ __('Ödəyib') }}</dt><dd>{!! $amounts($ob['buyer']['received'], '0') !!}</dd></div>
            </dl>
            @if($ob['buyer']['billed'])<div class="h-1.5 rounded-full bg-surface-2 overflow-hidden" title="{{ __('Ödənilib') }}"><div class="h-full bg-success rounded-full" style="width: {{ $pct($ob['buyer']['received'], $ob['buyer']['billed']) }}%"></div></div>@endif
            <div class="ob-goods {{ $ob['buyer']['goods'] ? 'is-open' : '' }}">
                <x-icon name="package" class="size-4 shrink-0"/>
                <span>@if($ob['buyer']['goods']){{ __('Bizim öhdəliyimiz:') }} <b class="font-mono">{!! $amounts($ob['buyer']['goods']) !!}</b> {{ __('dəyərində məhsul göndərməliyik') }} @else {{ __('Ödəniş gəlməyib — məhsul öhdəliyimiz yoxdur') }} @endif</span>
            </div>
        </article>

        {{-- Seller --}}
        <article class="card p-4 space-y-3">
            <header class="flex items-start justify-between gap-2">
                <div class="min-w-0"><div class="text-xs text-muted">{{ __('Satıcı') }}</div><div class="font-semibold truncate">{{ $ob['seller']['name'] ?? '—' }}</div></div>
                <span class="badge badge-amber shrink-0">{{ __('ona ödəyirik') }}</span>
            </header>
            <dl class="ob-rows">
                <div><dt>{{ __('Ödəməliyik') }}</dt><dd class="text-danger">{!! $amounts($ob['seller']['due'], $ob['seller']['invoiced'] ? __('tam ödənilib') : __('faktura yoxdur')) !!}</dd></div>
                <div><dt>{{ __('Ödəmişik') }}</dt><dd>{!! $amounts($ob['seller']['paid'], '0') !!}</dd></div>
            </dl>
            @if($ob['seller']['invoiced'])<div class="h-1.5 rounded-full bg-surface-2 overflow-hidden" title="{{ __('Ödənilib') }}"><div class="h-full bg-saffron rounded-full" style="width: {{ $pct($ob['seller']['paid'], $ob['seller']['invoiced']) }}%"></div></div>@endif
            <div class="ob-goods {{ $ob['seller']['goods'] ? 'is-open' : '' }}">
                <x-icon name="package" class="size-4 shrink-0"/>
                <span>@if($ob['seller']['goods']){{ __('Onun öhdəliyi: bizə') }} <b class="font-mono">{!! $amounts($ob['seller']['goods']) !!}</b> {{ __('dəyərində məhsul təhvil verməlidir') }} @else {{ __('Satıcıya ödəniş edilməyib') }} @endif</span>
            </div>
        </article>

        {{-- Logistics --}}
        <article class="card p-4 space-y-3">
            <header class="flex items-start justify-between gap-2">
                <div class="min-w-0"><div class="text-xs text-muted">{{ __('Logistika') }}</div><div class="font-semibold truncate">{{ $ob['logistics']['company'] ?? __('Logistika şirkəti') }}</div></div>
                @if($ob['logistics']['items'])<span class="badge {{ $ob['logistics']['forecast'] ? 'badge-amber' : 'badge-green' }} shrink-0">{{ $ob['logistics']['forecast'] ? 'proqnoz' : (collect($ob['logistics']['items'])->every(fn ($l) => $l['mode'] === 'act') ? __('akt üzrə') : __('dəqiq')) }}</span>@endif
            </header>
            @if($ob['logistics']['items'])
                <dl class="ob-rows">
                    <div><dt>{{ __('Ödəməliyik') }}</dt><dd class="text-danger">{!! $amounts($ob['logistics']['due']) !!}</dd></div>
                    @foreach($ob['logistics']['items'] as $l)
                        <div><dt class="text-xs">{{ $l['mode'] === 'act' ? $l['invoice'] : 'Faktura '.$l['invoice'] }}</dt><dd class="text-xs">{{ money($l['amount'], $l['currency']) }}</dd></div>
                    @endforeach
                </dl>
                @if($ob['logistics']['paid'])<div class="text-[11px] text-muted">{{ __('Aktlar üzrə ödənilib:') }} <span class="font-mono">{!! collect($ob['logistics']['paid'])->map(fn ($v, $c) => e(money($v, $c)))->implode(' · ') !!}</span></div>@endif
                <p class="text-[11px] text-muted">{{ __('Akt və ödəniş «Logistika» addımında aparılır.') }}</p>
            @else
                <p class="text-sm text-muted">{{ __('Alıcı üçün proforma hazır olanda logistika şirkəti qarşısında öhdəlik burada yaranacaq.') }}</p>
            @endif
        </article>
    </div>
</section>
