{{-- Totals of a Trade / project / all projects. $t = ProfitCalculator::totals(). --}}
@php
    $cards = [
        [__('Proqnoz mənfəət'), $t['forecast']['count'] ? $t['forecast']['profit'] : null, $t['forecast']['count'], 'Q − P − R − S', 'sparkles'],
        [__('Akt tarixinə mənfəət'), $t['act']['count'] ? $t['act']['BP'] : null, $t['act']['count'], 'BN − BO', 'truck'],
        [__('Xalis mənfəət (CBAR)'), $t['settle']['count'] ? $t['settle']['BT'] : null, $t['settle']['count'], 'BP + BQ + BR − BS − AJ − BB', 'scale'],
        [__('Yekun mənfəət'), $t['bank']['count'] ? $t['bank']['final'] : null, $t['bank']['count'], __('bank fərqləri və xərclərlə'), 'wallet'],
    ];
@endphp
<div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
    @foreach($cards as $i => [$label, $value, $count, $formula, $icon])
        <div class="card p-4" style="--i:{{ $i }}">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon :name="$icon" class="size-4"/> {{ $label }}</div>
            <div @class(['mt-1 text-xl font-semibold font-mono', 'text-success' => $value > 0, 'text-danger' => $value < 0, 'text-faint' => $value === null])>
                {{ $value === null ? '—' : money($value) }}
            </div>
            <div class="mt-1 text-[11px] text-faint flex justify-between gap-2"><span class="font-mono truncate">{{ $formula }}</span><span class="shrink-0">{{ $count }} / {{ $t['rows'] }} {{ __('faktura') }}</span></div>
        </div>
    @endforeach
</div>
@if($t['estimated'])
    <p class="-mt-3 mb-6 text-xs text-saffron flex items-center gap-1.5"><x-icon name="clock" class="size-3.5"/> {{ __('Bəzi fakturalar üzrə ödənişlər tam deyil — həmin hissə bugünkü CBAR kursu ilə təxmini hesablanıb.') }}</p>
@endif
