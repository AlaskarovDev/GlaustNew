{{-- Exchange-rate results (bank rate vs CBAR), per currency: "RUB məzənnə fərqi — xərc 613,68 ₼". Expects $fx = FxResults::for(...), optional $showTrade. --}}
@php $showTrade ??= false; @endphp
<section class="card overflow-hidden mb-6">
    <header class="px-5 py-4 border-b border-line flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold">{{ __('Məzənnə fərqləri') }}</h2>
            <p class="text-xs text-muted">{{ __('Valyuta alış-satışı, mədaxil və ödənişlərdə bankın kursu ilə CBAR arasındakı fərq, AZN') }}</p>
        </div>
        @if($fx['rows'])
            <div class="text-right">
                <div class="text-[11px] text-muted">{{ __('Xalis nəticə') }}</div>
                <div @class(['font-mono font-semibold text-lg', 'text-success' => $fx['net'] > 0, 'text-danger' => $fx['net'] < 0])>{{ ($fx['net'] < 0 ? '−' : ($fx['net'] > 0 ? '+' : '')).money(abs($fx['net'])) }}</div>
            </div>
        @endif
    </header>
    @if(! $fx['rows'])
        <p class="px-5 py-5 text-sm text-muted">{{ __('Məzənnə fərqi yoxdur — bütün əməliyyatlar CBAR kursu ilə aparılıb.') }}</p>
    @else
        <div class="p-5 flex flex-wrap gap-3 border-b border-line">
            @foreach($fx['currencies'] as $cur => $c)
                @if($c['loss'] > 0)
                    <div class="rounded-xl border border-danger/30 bg-danger-soft/40 px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-danger">{{ __(':cur məzənnə fərqi — xərc', ['cur' => $cur]) }}</div>
                        <div class="font-mono text-xl font-semibold text-danger">{{ money($c['loss']) }}</div>
                    </div>
                @endif
                @if($c['gain'] > 0)
                    <div class="rounded-xl border border-success/30 bg-success-soft/40 px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-success">{{ __(':cur məzənnə fərqi — gəlir', ['cur' => $cur]) }}</div>
                        <div class="font-mono text-xl font-semibold text-success">{{ money($c['gain']) }}</div>
                    </div>
                @endif
            @endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="table-g table-stack text-sm">
                <thead><tr><th class="w-28">{{ __('Tarix') }}</th><th class="w-40">{{ __('Əməliyyat') }}</th>@if($showTrade)<th>Trade</th>@endif<th>{{ __('Valyuta') }}</th><th>{{ __('Təsvir') }}</th><th class="!text-right">{{ __('Fərq (AZN)') }}</th></tr></thead>
                <tbody>
                @foreach($fx['rows'] as $r)
                    <tr>
                        <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($r['date']) }}</td>
                        <td data-label="{{ __('Əməliyyat') }}">{{ $r['kind'] }}</td>
                        @if($showTrade)<td data-label="Trade" class="font-mono text-xs">{{ $r['trade'] ?? '—' }}</td>@endif
                        <td data-label="{{ __('Valyuta') }}" class="font-mono font-semibold">{{ $r['cur'] }}</td>
                        <td data-label="{{ __('Təsvir') }}" class="text-ink-2">{{ $r['text'] }}</td>
                        <td data-label="{{ __('Fərq (AZN)') }}" @class(['num font-semibold', 'text-success' => $r['azn'] > 0, 'text-danger' => $r['azn'] < 0])>{{ ($r['azn'] < 0 ? '−' : '+').money(abs($r['azn'])) }}
                            <div class="text-[11px] font-normal text-faint">{{ $r['azn'] < 0 ? __('xərc') : __('gəlir') }}</div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
