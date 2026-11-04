<?php

namespace App\Support\Reports;

use App\Models\Deal;

/**
 * Layihə hesabatları — each project and its Trades, from the same Trade figures as the Təsisçi hesabatı (TradeReport):
 *   a Trade counts in the period of its act (the goods handed over): sale (AC), purchase (AA), logistics (BC),
 *   fees (AJ + BB), the bank's rates against CBAR (AL + AM), profit = «Gəlir — kurs fərqləri ilə» (BE), margin = BE ÷ AC;
 *   the money: received from the buyer (in the sale currency) and paid to the seller (in the purchase currency).
 * Trades without an act yet are «açıq»: shown with their money only.
 */
class ProjectReport
{
    /** @return array{projects: list<array>, open: list<array>, total: array} */
    public function build(string $from, string $to, ?int $projectId = null): array
    {
        $deals = Deal::with(['project:id,code,name', 'supplier:id,name', 'counterparty:id,name', 'payments', 'supplierPayments'])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))->get();
        $rows = $deals->isEmpty() ? [] : app(TradeReport::class)->rows($deals);
        $zero = ['revenue' => 0.0, 'purchase' => 0.0, 'logistics' => 0.0, 'fees' => 0.0, 'fx' => 0.0, 'profit' => 0.0];
        $projects = $open = [];
        $total = $zero;

        foreach (collect($rows)->groupBy(fn ($r) => $r['deal']->id) as $rs) {
            $deal = $rs->first()['deal'];
            $done = $rs->every(fn ($r) => ! $r['provisional'] && $r['BI'] instanceof \DateTimeInterface);
            $day = $done ? $rs->max(fn ($r) => $r['BI']->format('Y-m-d')) : null;
            $saleCur = $rs->first()['saleCur'];
            $cur = $rs->first()['cur'];
            $trade = ['code' => $deal->code, 'url' => route('deals.show', $deal), 'title' => ($deal->supplier?->name ?? '—').' → '.($deal->counterparty?->name ?? '—'),
                'invoices' => $rs->pluck('seller_no')->filter()->implode(', '), 'day' => $day,
                'sold' => (float) $rs->sum('H'), 'saleCur' => $saleCur, 'received' => (float) $deal->payments->where('currency', $saleCur)->sum('amount'),
                'bought' => (float) $rs->sum('D'), 'cur' => $cur, 'paid' => (float) $deal->supplierPayments->sum('amount')];
            if (! $done) {
                $open[] = $trade + ['project' => $deal->project?->name ?? __('Layihəsiz')];

                continue;
            }
            if ($day < $from || $day > $to) {
                continue;
            }
            $f = ['revenue' => (float) $rs->sum('AC'), 'purchase' => (float) $rs->sum('AA'), 'logistics' => (float) $rs->sum(fn ($r) => (float) ($r['BC'] ?? 0)),
                'fees' => (float) $rs->sum(fn ($r) => (float) ($r['AJ'] ?? 0) + (float) ($r['BB'] ?? 0)), 'fx' => (float) $rs->sum(fn ($r) => (float) ($r['AL'] ?? 0) + (float) ($r['AM'] ?? 0)),
                'profit' => (float) $rs->sum(fn ($r) => (float) ($r['BE'] ?? 0))];
            $f = array_map(fn ($v) => round($v, 2), $f);
            $key = $deal->project?->id ?? 0;
            $projects[$key] ??= ['name' => $deal->project?->name ?? __('Layihəsiz'), 'code' => $deal->project?->code, 'url' => $deal->project ? route('projects.show', $deal->project) : null,
                'trades' => [], 'sum' => $zero];
            $projects[$key]['trades'][] = $trade + $f + ['margin' => self::margin($f)];
            foreach ($f as $k => $v) {
                $projects[$key]['sum'][$k] = round($projects[$key]['sum'][$k] + $v, 2);
                $total[$k] = round($total[$k] + $v, 2);
            }
        }
        foreach ($projects as &$p) {
            usort($p['trades'], fn ($a, $b) => strcmp($a['day'], $b['day']));
            $p['sum']['margin'] = self::margin($p['sum']);
        }
        unset($p);
        uasort($projects, fn ($a, $b) => $b['sum']['profit'] <=> $a['sum']['profit']);
        $total['margin'] = self::margin($total);

        return ['projects' => array_values($projects), 'open' => $open, 'total' => $total];
    }

    private static function margin(array $f): ?float
    {
        return $f['revenue'] > 0 ? round($f['profit'] / $f['revenue'] * 100, 1) : null;
    }
}
