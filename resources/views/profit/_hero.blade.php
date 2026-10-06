{{-- Current result and the four stages. $t = ProfitCalculator::totals(), $meta = counts line. --}}
@php
    $stages = [
        [1, __('Proqnoz'), $t['forecast']['count'], $t['forecast']['profit'], 'Q − P − R − S', 'sparkles'],
        [2, __('Akt tarixinə'), $t['act']['count'], $t['act']['BP'], 'BN − BO', 'truck'],
        [3, __('Xalis (CBAR)'), $t['settle']['count'], $t['settle']['BT'], 'BP + BQ + BR − BS − AJ − BB', 'scale'],
        [4, __('Yekun'), $t['bank']['count'], $t['bank']['final'], 'BT − '.__('bank fərqləri').' − '.__('xərclər'), 'wallet'],
    ];
    $current = collect($stages)->filter(fn ($s) => $s[2] > 0)->max(fn ($s) => $s[0]) ?? 0;
    $tone = fn ($v) => $v > 0 ? 'pf-pos' : ($v < 0 ? 'pf-neg' : '');
@endphp
<section class="card p-5 sm:p-6 mb-6">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-5">
        <div>
            <div class="text-xs font-medium uppercase tracking-wider text-muted">{{ __('Cari nəticə') }}</div>
            <div @class(['mt-1 text-3xl sm:text-4xl font-semibold font-mono tabular-nums', $tone($t['best']) => $t['reached'], 'text-faint' => ! $t['reached']])>
                {{ $t['reached'] ? money($t['best']) : '—' }}
            </div>
            <div class="mt-1 text-sm text-muted">{{ $meta }}</div>
        </div>
        <div class="sm:text-right text-xs text-muted max-w-sm">
            {{ __('Hər faktura üzrə çatılan son mərhələnin mənfəəti; mərhələlər məlumat daxil edildikcə avtomatik dolur.') }}
            @if($t['estimated'])
                <div class="mt-1 inline-flex items-center gap-1 text-saffron"><x-icon name="clock" class="size-3.5"/> {{ __('Ödənilməmiş hissələr bugünkü CBAR kursu ilə — təxmini') }}</div>
            @endif
        </div>
    </div>
    <div class="pf-track">
        @foreach($stages as [$n, $label, $count, $value, $formula, $icon])
            <div @class(['pf-stage', 'is-reached' => $count > 0, 'is-current' => $n === $current])>
                <div class="pf-stage-head"><span class="pf-stage-no">{{ $n }}</span> {{ $label }} <x-icon :name="$icon" class="size-4 ml-auto opacity-60"/></div>
                <div @class(['pf-stage-value', $tone($value) => $count > 0])>{{ $count > 0 ? money($value) : '—' }}</div>
                <div class="pf-stage-foot"><span class="font-mono truncate" title="{{ $formula }}">{{ $formula }}</span><span class="shrink-0">{{ $count }}/{{ $t['rows'] }}</span></div>
            </div>
        @endforeach
    </div>
</section>
