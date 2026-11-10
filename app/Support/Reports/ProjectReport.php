<?php

namespace App\Support\Reports;

use App\Models\Deal;
use App\Services\Cbar\CurrencyRates;

/**
 * Layihə hesabatları — each project and its Trades, from the same Trade figures as the Təsisçi hesabatı (TradeReport):
 *   a Trade counts in the period of its act (the goods handed over): sale (AC), purchase (AA), logistics (BC),
 *   fees (AJ + BB), profit = the Yekun hesabat's «XALİS MƏNFƏƏT» (BT), margin = BT ÷ AC; the bank's rates against CBAR
 *   (AL + AM) are shown beside it, not taken off — the MZ books them as a bank expense (221.13), not as the Trade's;
 *   the money: received from the buyer (in the sale currency) and paid to the seller (in the purchase currency).
 * Trades without an act yet are «açıq»: shown with their money only.
 * $netFx — «Kurs fərqi çıxılmaqla»: the Trade's bank exchange result (AL + AM, the bank's rates against CBAR when the
 * rouble is sold and the euro bought) taken off every profit — the fact, the forecast, the operation day alike.
 * Beside the fact, on request, and the difference (fact − it); null — not computable for the Trade:
 *   fc — «Proqnozla»: every rate the forecast of the invoice (the RUB conversion's forecast EUR / RUB — TradeReport's
 *        «Proqnoz üzrə bax»: real amounts, dates and fees);
 *   op — «Əməliyyat tarixinə»: what the Trade earned on its operation day (the seller paid), before the act —
 *        AC − AA − AJ − the invoice's logistics forecast (I) at CBAR of the operation day; for open Trades too.
 */
class ProjectReport
{
    /** @return array{projects: list<array>, open: list<array>, total: array} */
    public function build(string $from, string $to, ?int $projectId = null, bool $forecast = false, bool $operation = false, bool $netFx = false): array
    {
        $deals = Deal::with(['project:id,code,name', 'supplier:id,name', 'counterparty:id,name', 'payments', 'supplierPayments'])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))->get();
        $rows = $deals->isEmpty() ? [] : app(TradeReport::class)->rows($deals);
        // the forecast view of the same Trades: its profit (BE) per Trade, null when an invoice has no forecast
        $fc = $fcRows = [];
        if ($forecast && $rows) {
            foreach (collect(app(TradeReport::class)->rows($deals, null, null, true))->groupBy(fn ($r) => $r['deal']->id) as $id => $frs) {
                $fc[$id] = $frs->contains(fn ($r) => ! $r['forecast'] || $r['BT'] === null) ? null : round((float) $frs->sum('BT'), 2);
                $fcRows[$id] = $frs;
            }
        }
        $zero = ['revenue' => 0.0, 'purchase' => 0.0, 'logistics' => 0.0, 'fees' => 0.0, 'fx' => 0.0, 'profit' => 0.0];
        $projects = $open = [];
        $total = $zero;
        $add = function (array &$sum, string $v, ?float $value, float $fact) {
            if ($value !== null) {
                $sum[$v] = round(($sum[$v] ?? 0) + $value, 2);
                $sum[$v.'Fact'] = round(($sum[$v.'Fact'] ?? 0) + $fact, 2);
                $sum[$v.'Count'] = ($sum[$v.'Count'] ?? 0) + 1;
            }
        };

        foreach (collect($rows)->groupBy(fn ($r) => $r['deal']->id) as $rs) {
            $deal = $rs->first()['deal'];
            $done = $rs->every(fn ($r) => ! $r['provisional'] && $r['BI'] instanceof \DateTimeInterface);
            $day = $done ? $rs->max(fn ($r) => $r['BI']->format('Y-m-d')) : null;
            $saleCur = $rs->first()['saleCur'];
            $cur = $rs->first()['cur'];
            [$op, $opRows] = $operation ? $this->operation($rs) : [null, []];
            // the bank's exchange result of the Trade, line by line (opened on a click), and — asked — taken off every profit
            [$fxAmt, $fxRows] = $this->exchange($rs);
            if ($netFx) {
                $op = $op === null ? null : round($op - $fxAmt, 2);
                $opRows[] = ['t' => __('Bank kurs fərqi'), 'c' => __('rubl satışı / avro alışı, bank − CBAR'), 'cf' => $done ? __('eyni') : null, 'v' => -$fxAmt, 'f' => $done ? -$fxAmt : null];
            }
            $trade = ['code' => $deal->code, 'name' => $deal->title ?: $deal->code, 'url' => route('deals.show', $deal), 'title' => ($deal->supplier?->name ?? '—').' → '.($deal->counterparty?->name ?? '—'),
                'invoices' => $rs->pluck('seller_no')->filter()->implode(', '), 'day' => $day,
                'opDay' => $rs->max(fn ($r) => $r['X'] instanceof \DateTimeInterface ? $r['X']->format('Y-m-d') : null),
                'sold' => (float) $rs->sum('H'), 'saleCur' => $saleCur, 'received' => (float) $deal->payments->where('currency', $saleCur)->sum('amount'),
                'bought' => (float) $rs->sum('D'), 'cur' => $cur, 'paid' => (float) $deal->supplierPayments->sum('amount'), 'op' => $op,
                // what the operation-day figure is made of (the page opens it on a click)
                'opWhy' => $operation ? ['h' => ($deal->title ?: 'Trade '.$deal->code).' · '.__('Əməliyyat tarixinə və fakt'), 'a' => __('Əməliyyat tarixinə'),
                    's' => __('əməliyyat').': '.($rs->max(fn ($r) => $r['X'] instanceof \DateTimeInterface ? $r['X']->format('Y-m-d') : null) ? azdate($rs->max(fn ($r) => $r['X'] instanceof \DateTimeInterface ? $r['X']->format('Y-m-d') : null)) : '—')
                        .' · '.__('akt').': '.($day ? azdate($day) : __('hələ yoxdur')),
                    'rows' => $opRows, 'v' => $op, 'f' => $done ? round((float) $rs->sum(fn ($r) => (float) ($r['BT'] ?? 0)) - ($netFx ? $fxAmt : 0), 2) : null] : null,
                'fcWhy' => $forecast ? $this->forecastWhy($deal->title ?: 'Trade '.$deal->code, $fcRows[$deal->id] ?? collect(), $rs, $done, $netFx ? $fxAmt : 0.0) : null,
                'fxWhy' => ['kind' => 'fx', 'h' => ($deal->title ?: 'Trade '.$deal->code).' · '.__('Bank kurs fərqi necə yarandı'), 's' => __('bankın kursu ilə CBAR arasındakı fərq'), 'rows' => $fxRows, 'v' => -$fxAmt]];
            if (! $done) {
                $open[] = $trade + ['project' => $deal->project?->name ?? __('Layihəsiz')];

                continue;
            }
            if ($day < $from || $day > $to) {
                continue;
            }
            $f = ['revenue' => (float) $rs->sum('AC'), 'purchase' => (float) $rs->sum('AA'), 'logistics' => (float) $rs->sum(fn ($r) => (float) ($r['BC'] ?? 0)),
                'fees' => (float) $rs->sum(fn ($r) => (float) ($r['AJ'] ?? 0) + (float) ($r['BB'] ?? 0)), 'fx' => (float) $rs->sum(fn ($r) => (float) ($r['AL'] ?? 0) + (float) ($r['AM'] ?? 0)),
                'profit' => (float) $rs->sum(fn ($r) => (float) ($r['BT'] ?? 0)) - ($netFx ? $fxAmt : 0)];
            $f = array_map(fn ($v) => round($v, 2), $f);
            $key = $deal->project?->id ?? 0;
            $projects[$key] ??= ['name' => $deal->project?->name ?? __('Layihəsiz'), 'code' => $deal->project?->code, 'url' => $deal->project ? route('projects.show', $deal->project) : null,
                'trades' => [], 'sum' => $zero];
            $fp = isset($fc[$deal->id]) ? round($fc[$deal->id] - ($netFx ? $fxAmt : 0), 2) : null;
            $projects[$key]['trades'][] = $trade + $f + ['margin' => self::margin($f),
                'fc' => $fp, 'diff' => $fp === null ? null : round($f['profit'] - $fp, 2), 'opDiff' => $op === null ? null : round($f['profit'] - $op, 2)];
            foreach ($f as $k => $v) {
                $projects[$key]['sum'][$k] = round($projects[$key]['sum'][$k] + $v, 2);
                $total[$k] = round($total[$k] + $v, 2);
            }
            // each view's totals cover the Trades it has a figure for; their fact beside it, for a fair difference
            foreach (['fc' => $fp, 'op' => $op] as $v => $value) {
                $add($projects[$key]['sum'], $v, $value, $f['profit']);
                $add($total, $v, $value, $f['profit']);
            }
        }
        $finish = function (array $sum): array {
            $sum['margin'] = self::margin($sum);
            foreach (['fc' => 'diff', 'op' => 'opDiff'] as $v => $d) {
                $sum += [$v => null, $v.'Fact' => null, $v.'Count' => 0];
                $sum[$d] = $sum[$v] === null ? null : round($sum[$v.'Fact'] - $sum[$v], 2);
            }

            return $sum;
        };
        foreach ($projects as &$p) {
            usort($p['trades'], fn ($a, $b) => strcmp($a['day'], $b['day']));
            $p['sum'] = $finish($p['sum']);
        }
        unset($p);
        uasort($projects, fn ($a, $b) => $b['sum']['profit'] <=> $a['sum']['profit']);
        $openOp = array_values(array_filter(array_column($open, 'op'), fn ($v) => $v !== null));

        return ['projects' => array_values($projects), 'open' => $open, 'total' => $finish($total),
            'openOp' => $openOp ? ['sum' => round(array_sum($openOp), 2), 'count' => count($openOp)] : null];
    }

    /**
     * The Trade's profit on its operation day (see the class) and, line by line, what it is made of beside the fact after
     * the act (BT) and the difference: [value, rows]. The sale, the purchase and the transfer fee are the same in both
     * (CBAR of the seller's payment day); the logistics differ — the invoice's forecast against what was paid, and its fee.
     * The value is null while the seller is unpaid or a rate is missing (the rows say what).
     *
     * @return array{0: ?float, 1: list<array{t: string, c: ?string, cf: ?string, v: ?float, f: ?float}>}
     */
    private function operation(\Illuminate\Support\Collection $rs): array
    {
        $sum = 0.0;
        $rows = [];
        $missing = false;
        foreach ($rs as $r) {
            $inv = __('satıcı fakturası').' '.$r['seller_no'];
            $acted = ! $r['provisional'] && $r['BI'] instanceof \DateTimeInterface;
            $fact = fn ($v) => $acted && $v !== null ? round((float) $v, 2) : null;
            if ($r['AC'] === null || $r['AA'] === null) {
                $rows[] = ['t' => $inv, 'c' => $r['AA'] === null ? __('satıcıya hələ ödənilməyib') : __('satışın kursu yoxdur'), 'cf' => null, 'v' => null, 'f' => null];
                $missing = true;

                continue;
            }
            $same = fn (string $t, float $v, string $c) => ['t' => $t, 'c' => $c, 'cf' => $acted ? __('eyni') : null, 'v' => $v, 'f' => $acted ? $v : null];
            $rows[] = $same(__('Satış').' · '.($r['buyer_no'] ? __('alıcı fakturası').' '.$r['buyer_no'] : $inv), round((float) $r['AC'], 2),
                num((float) $r['H']).' '.$r['saleCur'].' × '.rate_fmt((float) $r['Z']).' CBAR');
            $rows[] = $same(__('Alış').' · '.$inv, -round((float) $r['AA'], 2), num((float) $r['D']).' '.$r['cur'].' × '.rate_fmt((float) $r['Y']).' CBAR');
            if (abs((float) ($r['AJ'] ?? 0)) >= 0.005) {
                $rows[] = $same(__('Köçürmə komissiyası'), -round((float) $r['AJ'], 2), num((float) $r['J']).' '.$r['cur'].' × '.rate_fmt((float) $r['Y']).' CBAR');
            }
            // the logistics: the forecast on the operation day against what was paid
            $logistics = 0.0;
            $c = __('fakturada logistika proqnozu yoxdur');
            if ($r['I'] !== null && (float) $r['I'] != 0.0) {
                $rate = match (true) {
                    $r['logCur'] === null, $r['logCur'] === 'AZN' => 1.0,
                    $r['logCur'] === $r['saleCur'] => $r['Z'],
                    $r['logCur'] === $r['cur'] => $r['Y'],
                    default => $r['X'] instanceof \DateTimeInterface ? app(CurrencyRates::class)->tryRate($r['logCur'], $r['X']->format('Y-m-d')) : null,
                };
                if ($rate === null) {
                    $missing = true;
                    $c = num((float) $r['I']).' '.$r['logCur'].' — '.__('kurs yoxdur');
                } else {
                    $logistics = (float) $r['I'] * (float) $rate;
                    $c = num((float) $r['I']).' '.($r['logCur'] ?? 'AZN').' × '.rate_fmt((float) $rate).' CBAR ('.__('proqnoz').')';
                }
            }
            $cf = $r['AX'] !== null ? num((float) $r['AX']).' '.$r['axCur'].' × '.rate_fmt((float) $r['BA']).' ('.__('ödəniş günü, ödənilib').')' : __('logistika ödənişi yoxdur');
            $rows[] = ['t' => __('Logistika'), 'c' => $c, 'cf' => $acted ? $cf : null, 'v' => -round($logistics, 2), 'f' => ($f = $fact($r['BC'])) === null ? null : -$f];
            if ($acted && abs((float) $r['BB']) >= 0.005) {
                $rows[] = ['t' => __('Logistika komissiyası'), 'c' => __('proqnozda yoxdur'), 'cf' => __('bankın tutduğu'), 'v' => 0.0, 'f' => -round((float) $r['BB'], 2)];
            }
            $sum += (float) $r['AC'] - (float) $r['AA'] - (float) ($r['AJ'] ?? 0) - $logistics;
        }

        return [$missing ? null : round($sum, 2), $rows];
    }

    /**
     * The forecast profit line by line beside the fact (the page opens it on a click): every rate the invoice's forecast
     * (N for its currency, O for the sale's, the logistics' own) against the real ones; amounts and fees the same.
     */
    private function forecastWhy(string $code, \Illuminate\Support\Collection $frs, \Illuminate\Support\Collection $rs, bool $done, float $fx): array
    {
        $facts = $rs->keyBy(fn ($r) => $r['invoice']->id);
        $rows = [];
        $ok = $frs->isNotEmpty();
        foreach ($frs as $q) {
            $r = $facts->get($q['invoice']->id);
            $acted = $done && $r !== null;
            $inv = __('satıcı fakturası').' '.$q['seller_no'];
            if (! $q['forecast']) {
                $rows[] = ['t' => $inv, 'c' => __('fakturada proqnoz kursu yoxdur'), 'cf' => null, 'v' => null, 'f' => null];
                $ok = false;

                continue;
            }
            $f = fn (string $k) => $acted && $r[$k] !== null ? round((float) $r[$k], 2) : null;
            $neg = fn (?float $v) => $v === null ? null : -$v;
            $rate = fn ($v) => $v === null ? '—' : rate_fmt((float) $v);
            // the same figures apart, for the Excel: the amount and its currency, the forecast rate and the real one
            $x = fn ($n, $cur, $rq, $rf) => ['n' => $n === null ? null : (float) $n, 'cur' => $cur, 'rq' => $rq === null ? null : (float) $rq, 'rf' => $acted && $rf !== null ? (float) $rf : null];
            $rows[] = ['t' => __('Satış').' · '.($q['buyer_no'] ? __('alıcı fakturası').' '.$q['buyer_no'] : $inv),
                'c' => num((float) $q['H']).' '.$q['saleCur'].' × '.$rate($q['Z']).' ('.__('proqnoz').')', 'v' => $q['AC'] === null ? null : round((float) $q['AC'], 2),
                'cf' => $acted ? num((float) $r['H']).' '.$r['saleCur'].' × '.$rate($r['Z']).' CBAR' : null, 'f' => $f('AC'), 'x' => $x($q['H'], $q['saleCur'], $q['Z'], $r['Z'] ?? null), 'kind' => 'sale'];
            $rows[] = ['t' => __('Alış').' · '.$inv, 'c' => num((float) $q['D']).' '.$q['cur'].' × '.$rate($q['Y']).' ('.__('proqnoz').')', 'v' => $q['AA'] === null ? null : -round((float) $q['AA'], 2),
                'cf' => $acted ? num((float) $r['D']).' '.$r['cur'].' × '.$rate($r['Y']).' CBAR' : null, 'f' => $neg($f('AA')), 'x' => $x($q['D'], $q['cur'], $q['Y'], $r['Y'] ?? null), 'kind' => 'purchase'];
            if (abs((float) ($q['AJ'] ?? 0)) >= 0.005 || abs((float) ($r['AJ'] ?? 0)) >= 0.005) {
                $rows[] = ['t' => __('Köçürmə komissiyası'), 'c' => num((float) $q['J']).' '.$q['cur'].' × '.$rate($q['Y']).' ('.__('proqnoz').')', 'v' => -round((float) ($q['AJ'] ?? 0), 2),
                    'cf' => $acted ? num((float) $r['J']).' '.$r['cur'].' × '.$rate($r['Y']).' CBAR' : null, 'f' => $neg($f('AJ')), 'x' => $x($q['J'], $q['cur'], $q['Y'], $r['Y'] ?? null), 'kind' => 'fee'];
            }
            $rows[] = ['t' => __('Logistika'), 'c' => $q['AX'] !== null ? num((float) $q['AX']).' '.$q['axCur'].' × '.$rate($q['BA']).' ('.__('proqnoz').')' : __('logistika ödənişi yoxdur'),
                'v' => -round((float) ($q['BC'] ?? 0), 2), 'cf' => $acted ? ($r['AX'] !== null ? num((float) $r['AX']).' '.$r['axCur'].' × '.$rate($r['BA']).' ('.__('ödəniş günü').')' : __('logistika ödənişi yoxdur')) : null,
                'f' => $neg($f('BC')), 'x' => $x($q['AX'], $q['axCur'] ?? null, $q['BA'], $r['BA'] ?? null), 'kind' => 'logistics'];
            if (abs((float) ($q['BB'] ?? 0)) >= 0.005 || abs((float) ($r['BB'] ?? 0)) >= 0.005) {
                $rows[] = ['t' => __('Logistika komissiyası'), 'c' => __('bankın tutduğu'), 'v' => -round((float) ($q['BB'] ?? 0), 2), 'cf' => $acted ? __('bankın tutduğu') : null, 'f' => $neg($f('BB')), 'kind' => 'logistics_fee'];
            }
        }
        if ($fx != 0.0) {
            $rows[] = ['t' => __('Bank kurs fərqi'), 'c' => __('real — rubl satışı / avro alışı'), 'cf' => $done ? __('eyni') : null, 'v' => -$fx, 'f' => $done ? -$fx : null, 'kind' => 'fx'];
        }
        $v = $ok && ! $frs->contains(fn ($q) => $q['BT'] === null) ? round((float) $frs->sum('BT') - $fx, 2) : null;

        return ['h' => $code.' · '.__('Proqnoz və fakt'), 's' => __('proqnoz: fakturadakı bank proqnozu kursları ilə; fakt: real kurslar ilə'), 'a' => __('Proqnoz'),
            'rows' => $rows, 'v' => $v, 'f' => $done ? round((float) $rs->sum(fn ($r) => (float) ($r['BT'] ?? 0)) - $fx, 2) : null];
    }

    /**
     * The Trade's bank exchange result and how it arose: [AZN, rows]. The roubles from the buyer are sold to the bank below
     * CBAR (AL = sold RUB × (CBAR − the bank's rate)), the euros for the seller bought above CBAR (AM = bought EUR × (the
     * bank's rate − CBAR)) — both a cost, as the sale and the purchase are counted at CBAR.
     *
     * @return array{0: float, 1: list<array{t: string, c: ?string, v: float}>}
     */
    private function exchange(\Illuminate\Support\Collection $rs): array
    {
        $rows = [];
        $sum = 0.0;
        foreach ($rs as $r) {
            $inv = __('satıcı fakturası').' '.$r['seller_no'];
            if ($r['AO'] !== null && (float) $r['AO'] != 0.0) {
                $v = (float) ($r['AL'] ?? 0);
                $rows[] = ['t' => __('Rubl satışı (alıcının pulu)').' · '.$inv, 'c' => $r['AL'] === null ? __('kurs yoxdur')
                    : num((float) $r['AO']).' '.$r['saleCur'].' × ('.rate_fmt((float) $r['Z']).' CBAR − '.rate_fmt((float) $r['AG']).' '.__('bank').')', 'v' => -round($v, 2)];
                $sum += $v;
            }
            if ($r['AP'] !== null && (float) $r['AP'] != 0.0) {
                $v = (float) ($r['AM'] ?? 0);
                $rows[] = ['t' => __('Avro alışı (satıcıya)').' · '.$inv, 'c' => $r['AM'] === null ? __('kurs yoxdur')
                    : num((float) $r['AP']).' '.$r['cur'].' × ('.rate_fmt((float) $r['AF']).' '.__('bank').' − '.rate_fmt((float) $r['Y']).' CBAR)', 'v' => -round($v, 2)];
                $sum += $v;
            }
        }
        if (! $rows) {
            $rows[] = ['t' => __('Bu Trade üçün valyuta alış-satışı yazılmayıb'), 'c' => null, 'v' => 0.0];
        }

        return [round($sum, 2), $rows];
    }

    private static function margin(array $f): ?float
    {
        return $f['revenue'] > 0 ? round($f['profit'] / $f['revenue'] * 100, 1) : null;
    }
}
