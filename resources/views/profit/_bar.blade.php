{{-- Four-segment progress: which stages a row (or a set of rows) has reached. $r = calculator row, or $t = totals. --}}
@php
    if (! empty($r)) {
        $on = [$r['forecast'] !== null, $r['act'] !== null, $r['settle'] !== null, $r['bank'] !== null && ! $r['estimated']];
        $part = [false, false, false, $r['bank'] !== null && $r['estimated']];
    } else {
        $n = max(1, $t['rows']);
        $counts = [$t['forecast']['count'], $t['act']['count'], $t['settle']['count'], $t['bank']['count']];
        $on = array_map(fn ($c) => $t['rows'] && $c === $t['rows'], $counts);
        $part = array_map(fn ($c) => $c > 0 && $c < $t['rows'], $counts);
        if ($on[3] && $t['estimated']) {
            [$on[3], $part[3]] = [false, true];
        }
    }
@endphp
<div class="pf-bar {{ $class ?? '' }}" title="{{ __('Proqnoz') }} · {{ __('Akt tarixinə') }} · {{ __('Xalis (CBAR)') }} · {{ __('Yekun') }}">
    @foreach($on as $i => $o)<span @class(['is-on' => $o, 'is-part' => ! $o && $part[$i]])></span>@endforeach
</div>
