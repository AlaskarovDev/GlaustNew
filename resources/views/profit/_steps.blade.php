{{-- One shipment (seller invoice) of a Trade, calculated step by step. $r = ProfitCalculator row. --}}
@php
    $cur = $r['cur']; $sc = $r['saleCur'];
    $f = $r['forecast']; $a = $r['act']; $s = $r['settle']; $b = $r['bank'];
    $line = function ($ref, $label, $formula, $value, $strong = false, $tone = null) {
        return compact('ref', 'label', 'formula', 'value', 'strong', 'tone');
    };
    $signed = fn ($v) => $v === null ? '—' : ($v > 0 ? '+' : ($v < 0 ? '−' : '')).money(abs($v));
    $steps = [];
    $steps[] = ['n' => 1, 'title' => __('Proqnoz'), 'sub' => $f ? __('Faktura tarixinə proqnoz / CBAR kursları').' · '.azdate($f['date']) : null, 'lines' => $f ? [
        $line('P', __('Alış (satıcıya)'), 'D × N = '.num($r['D']).' × '.rate_fmt($f['N']), money($f['P'])),
        $line('Q', __('Satış (alıcıdan)'), 'H × O = '.num($r['H']).' × '.rate_fmt($f['O']), money($f['Q'])),
        $line('R', __('Logistika xərci'), __('fakturadakı logistika, AZN'), money($f['R'])),
        $line('S', __('Köçürmə komissiyası'), money($f['fee'], $cur).' × N', money($f['S'])),
        $line('', __('Proqnoz mənfəət'), 'Q − P − R − S', $signed($f['profit']), true, $f['profit'] >= 0 ? 'success' : 'danger'),
    ] : null, 'empty' => __('RUB konvertasiyası (proqnoz kursları) tətbiq olunmayıb.')];
    $steps[] = ['n' => 2, 'title' => __('Akt tarixinə mənfəət'), 'sub' => $a ? __('Logistika').': '.$a['numbers'].' · '.azdate($a['date']) : null, 'lines' => $a ? [
        $line('BJ / BK', __('CBAR kursları akt tarixinə'), $cur.' '.rate_fmt($a['BJ']).' · '.$sc.' '.rate_fmt($a['BK']), ''),
        $line('BL', __('Alış, akt tarixinə'), 'D × BJ', money($a['BL'])),
        $line('BM', __('Satış, akt tarixinə'), 'H × BK', money($a['BM'])),
        $line('BN', __('Akt tarixinə gəlir'), 'BM − BL', money($a['BN'])),
        $line('BO', __('Logistika, akt tarixinə'), collect($a['amounts'])->map(fn ($v, $c) => money($v, $c))->implode(' + ').' → AZN', money($a['BO'])),
        $line('BP', __('Akt tarixinə mənfəət'), 'BN − BO', $signed($a['BP']), true, $a['BP'] >= 0 ? 'success' : 'danger'),
    ] : null, 'empty' => __('Logistika aktı daxil ediləndə hesablanır (Trade → Logistika).')];
    $steps[] = ['n' => 3, 'title' => __('Kurs fərqləri və xalis mənfəət'), 'sub' => $s ? __('Satıcıya ödəniş (əməliyyat) tarixi').' · '.azdate($s['date']).($s['estimated'] ? ' · '.__('təxmini') : '') : null, 'lines' => $s ? array_values(array_filter([
        $line('Y / Z', __('CBAR kursları ödəniş tarixinə'), $cur.' '.rate_fmt($s['Y']).' · '.$sc.' '.rate_fmt($s['Z']), ''),
        $line('AB', __('Alış, ödəniş tarixinə'), 'D × Y', money($s['AB'])),
        $line('AC', __('Satış, ödəniş tarixinə'), 'H × Z', money($s['AC'])),
        isset($s['BQ']) ? $line('BQ', __('Satış üzrə kurs fərqi'), 'AC − BM', $signed($s['BQ']), false, $s['BQ'] < 0 ? 'danger' : 'success') : null,
        isset($s['BR']) ? $line('BR', __('Alış üzrə kurs fərqi'), 'BL − AB', $signed($s['BR']), false, $s['BR'] < 0 ? 'danger' : 'success') : null,
        $line('BC', __('Logistika, ödəniş tarixinə'), __('ödənilən akt × CBAR (ödəniş günü)'), money($s['BC'])),
        isset($s['BS']) ? $line('BS', __('Logistika kurs fərqi'), 'BC − BO', $signed(-$s['BS']), false, $s['BS'] > 0 ? 'danger' : 'success') : null,
        $line('AJ', __('Köçürmə komissiyası (satıcıya)'), __('komissiya × CBAR'), '−'.money($s['AJ'])),
        $line('BB', __('Logistika köçürmə komissiyası'), __('bankın tutduğu, AZN'), '−'.money($s['BB'])),
        $line('BT', __('Xalis mənfəət (CBAR ilə)'), isset($s['BQ']) ? 'BP + BQ + BR − BS − AJ − BB' : 'AC − AB − BC − AJ − BB', $signed($s['BT']), true, $s['BT'] >= 0 ? 'success' : 'danger'),
    ])) : null, 'empty' => __('Satıcıya ödəniş ediləndə hesablanır (Trade → Mədaxillər).')];
    $steps[] = ['n' => 4, 'title' => __('Bank kursları və yekun'), 'sub' => $b ? __('Bankın tətbiq etdiyi kurslarla CBAR arasındakı fərq') : null, 'lines' => $b ? [
        $line('AL', __('Alıcıdan daxilolma: bank ↔ CBAR'), __('mədaxillərin kurs fərqi'), $signed($b['incoming']), false, $b['incoming'] < 0 ? 'danger' : null),
        $line('AM', __('Satıcıya ödəniş: bank ↔ CBAR'), __('ödənişin kurs fərqi'), $signed($b['supplier']), false, $b['supplier'] < 0 ? 'danger' : null),
        $line('', __('Logistika ödənişi: bank ↔ CBAR'), __('ödənişin kurs fərqi'), $signed($b['logistics']), false, $b['logistics'] < 0 ? 'danger' : null),
        $line('', __('Trade-ə bağlı digər xərclər'), __('Xərclər bölməsindən'), '−'.money($b['expenses'])),
        $line('BE', __('Yekun mənfəət'), 'BT + ' . __('bank fərqləri') . ' − ' . __('xərclər'), $signed($b['final']), true, $b['final'] >= 0 ? 'success' : 'danger'),
    ] : null, 'empty' => __('Satıcıya ödənişdən sonra hesablanır.')];
@endphp

<div class="grid xl:grid-cols-2 gap-4">
    @foreach($steps as $step)
        <section @class(['rounded-xl border p-4', 'border-line bg-surface' => $step['lines'], 'border-dashed border-line-strong' => ! $step['lines']])>
            <header class="flex items-start gap-3 mb-3">
                <span @class(['grid place-items-center size-7 rounded-full font-mono text-xs font-semibold shrink-0', 'bg-brand text-white' => $step['lines'], 'bg-surface-2 text-muted' => ! $step['lines']])>{{ $step['n'] }}</span>
                <div class="min-w-0">
                    <h4 class="text-sm font-semibold">{{ $step['title'] }}</h4>
                    @if($step['sub'])<p class="text-[11px] text-muted">{{ $step['sub'] }}</p>@endif
                </div>
            </header>
            @if($step['lines'])
                <table class="w-full text-sm">
                    <tbody>
                        @foreach($step['lines'] as $l)
                            <tr @class(['border-t border-line' => $l['strong']])>
                                <td class="py-1 pr-2 w-14 align-top">@if($l['ref'])<span class="font-mono text-[10px] text-faint">{{ $l['ref'] }}</span>@endif</td>
                                <td @class(['py-1 pr-3 align-top', 'font-semibold' => $l['strong']])>{{ $l['label'] }}<div class="text-[11px] text-muted font-mono">{{ $l['formula'] }}</div></td>
                                <td @class(['py-1 text-right font-mono whitespace-nowrap align-top', 'font-semibold' => $l['strong'], 'text-success' => $l['tone'] === 'success', 'text-danger' => $l['tone'] === 'danger'])>{{ $l['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-xs text-muted">{{ $step['empty'] }}</p>
            @endif
        </section>
    @endforeach
</div>
@foreach($r['notes'] as $note)
    <p class="mt-3 text-xs text-muted flex items-start gap-1.5"><x-icon name="info" class="size-3.5 mt-0.5 shrink-0"/> {{ $note }}</p>
@endforeach
@if($r['estimated'])
    <p class="mt-2 text-xs text-saffron flex items-start gap-1.5"><x-icon name="clock" class="size-3.5 mt-0.5 shrink-0"/> {{ __('Təxmini: hələ ödənilməmiş hissə bugünkü CBAR kursu ilə qiymətləndirilib. Ödənişlər tamamlandıqca dəqiqləşir.') }}</p>
@endif
