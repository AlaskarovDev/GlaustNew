<?php

namespace App\Support\Reports;

use App\Models\BankTransaction;
use App\Models\Deal;
use App\Models\Expense;
use App\Services\SupplierPaymentService;

/**
 * Təsisçi hesabatı — revenue, expenses and net profit at a glance, for a period, a project or a Trade.
 *   The headline (revenue, expenses, profit, the months, the cost structure) is the «Mənfəət və zərər» of the same months
 *   and filter — one profit in every report; the Trades and projects are listed by the Yekun hesabat's «XALİS MƏNFƏƏT» (BT).
 *   A Trade counts in the period of its act (the goods handed over): revenue = the sale at CBAR of the payment day (AC);
 *   expenses = the purchase (AA), the logistics (BC), the fees (AJ + BB) and the bank's rates against CBAR (AL + AM) —
 *   the Trade's profit is the Yekun hesabat's «Gəlir — kurs fərqləri ilə» (BE).
 *   The company's running expenses (salaries, taxes on payroll, services, domestic bank fees — not of a Trade, not a
 *   profit tax or an asset) and other income (a deposit's interest) count for the whole company only, not for a project
 *   or Trade filter.
 * Trades without an act yet are the pipeline: their forecast profit (FP).
 */
class FounderReport
{
    private ?int $projectId = null;

    private ?int $dealId = null;

    public function build(string $from, string $to, ?int $projectId = null, ?int $dealId = null): array
    {
        $this->projectId = $projectId;
        $this->dealId = $dealId;
        $deals = Deal::with(['project:id,code,name', 'supplier:id,name', 'counterparty:id,name'])
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($dealId, fn ($q) => $q->whereKey($dealId))->get();
        $rows = $deals->isEmpty() ? [] : app(TradeReport::class)->rows($deals);
        $company = ! $projectId && ! $dealId;

        $span = fn (string $a, string $b) => $this->period($rows, $a, $b, $company);
        $now = $span($from, $to);

        // the same length right before, to compare with
        $days = (int) \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1;
        $prevTo = \Carbon\Carbon::parse($from)->subDay()->toDateString();
        $prevFrom = \Carbon\Carbon::parse($prevTo)->subDays($days - 1)->toDateString();
        $prev = $span($prevFrom, $prevTo);

        // the pipeline: Trades still without an act (or acted later than the period)
        $open = collect($rows)->filter(fn ($r) => ! $this->actDay($r) || $this->actDay($r) > $to);

        return $now + [
            'from' => $from, 'to' => $to, 'company' => $company,
            'previous' => ['from' => $prevFrom, 'to' => $prevTo, 'revenue' => $prev['revenue'], 'expenses' => $prev['expenses'], 'profit' => $prev['profit']],
            'open' => ['count' => $open->pluck('deal.id')->unique()->count(), 'forecast' => round((float) $open->sum(fn ($r) => (float) $r['FP']), 2),
                'revenue' => round((float) $open->sum(fn ($r) => (float) $r['Q']), 2)],
        ];
    }

    private function actDay(array $r): ?string
    {
        return $r['BI'] instanceof \DateTimeInterface ? $r['BI']->format('Y-m-d') : null;
    }

    private function period(array $rows, string $from, string $to, bool $company): array
    {
        $months = [];
        for ($m = \Carbon\Carbon::parse($from)->startOfMonth(); $m->format('Y-m') <= substr($to, 0, 7); $m->addMonthNoOverflow()) {
            $months[$m->format('Y-m')] = ['revenue' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
        }
        $parts = ['purchase' => 0.0, 'logistics' => 0.0, 'fees' => 0.0, 'fx' => 0.0, 'overhead' => 0.0];
        $trades = $projects = $overheads = [];
        $revenue = $other = 0.0;
        $incomplete = 0;

        foreach ($rows as $r) {
            $day = $this->actDay($r);
            if (! $day || $day < $from || $day > $to) {
                continue;
            }
            if ($r['AC'] === null || $r['BT'] === null) {
                $incomplete++;

                continue;
            }
            $rev = (float) $r['AC'];
            $p = ['purchase' => (float) $r['AA'], 'logistics' => (float) ($r['BC'] ?? 0), 'fees' => (float) ($r['AJ'] ?? 0) + (float) ($r['BB'] ?? 0), 'fx' => (float) ($r['AL'] ?? 0) + (float) ($r['AM'] ?? 0)];
            $profit = (float) $r['BT'];   // the Yekun hesabat's «XALİS MƏNFƏƏT»
            $revenue += $rev;
            foreach ($p as $k => $v) {
                $parts[$k] += $v;
            }
            $m = substr($day, 0, 7);
            $months[$m]['revenue'] += $rev;
            $months[$m]['expenses'] += $rev - $profit;
            $months[$m]['profit'] += $profit;
            $id = $r['deal']->id;
            $trades[$id] ??= ['code' => $r['deal']->code, 'title' => ($r['deal']->supplier?->name ?? '—').' → '.($r['deal']->counterparty?->name ?? '—'),
                'invoices' => [], 'day' => $day, 'revenue' => 0.0, 'profit' => 0.0, 'url' => route('deals.show', $r['deal'])];
            $trades[$id]['invoices'][] = $r['seller_no'];
            $trades[$id]['revenue'] += $rev;
            $trades[$id]['profit'] += $profit;
            $trades[$id]['day'] = max($trades[$id]['day'], $day);
            $pr = $r['deal']->project;
            $key = $pr?->id ?? 0;
            $projects[$key] ??= ['name' => $pr ? $pr->name : __('Layihəsiz'), 'code' => $pr?->code, 'revenue' => 0.0, 'profit' => 0.0, 'trades' => []];
            $projects[$key]['revenue'] += $rev;
            $projects[$key]['profit'] += $profit;
            $projects[$key]['trades'][$id] = true;
        }

        // the headline is the MZ of the same months and filter — the same profit as «Mənfəət və zərər»
        $pl = (new ProfitLossReport)->build(substr($from, 0, 7), substr($to, 0, 7), $this->projectId, $this->dealId);
        $line = fn (string $code, string $m) => (float) ($pl['lines'][$code]['values'][$m] ?? 0);
        $parts = ['purchase' => 0.0, 'logistics' => 0.0, 'fees' => 0.0, 'fx' => 0.0, 'overhead' => 0.0];
        $overheads = [];
        $revenue = $other = 0.0;
        foreach ($pl['months'] as $m) {
            $fx = -($line('214', $m) + $line('219.3', $m));   // the net exchange result, a loss as an expense
            $months[$m] = ['revenue' => $line('217.2', $m) + $line('212', $m), 'expenses' => $pl['out'][$m] + $fx, 'profit' => $pl['result'][$m]];
            $revenue += $line('217.2', $m);
            $other += $line('212', $m);
            $parts['fx'] += $fx;
            $parts['fees'] += $line('221.13', $m);
        }
        foreach ($pl['lines'] as $code => $l) {
            if ($l['side'] !== 'out' || $code === '221.13') {
                continue;
            }
            if ($code === '232.1') {
                $buy = collect(array_merge([], ...array_values($l['items'])))->filter(fn ($i) => str_ends_with($i['t'], ' · '.__('alış')))->sum('v');
                $parts['purchase'] += $buy;
                $parts['logistics'] += $l['total'] - $buy;

                continue;
            }
            $parts['overhead'] += $l['total'];
            if (abs($l['total']) >= 0.005) {
                $overheads[$code.' · '.$l['label']] = $l['total'];
            }
        }
        $income = $revenue + $other;
        $profit = round(array_sum($pl['result']), 2);
        $expenses = $income - $profit;
        arsort($overheads);
        $trades = array_values($trades);
        usort($trades, fn ($a, $b) => $b['profit'] <=> $a['profit']);
        $projects = array_values(array_map(fn ($p) => $p + ['count' => count($p['trades'])], $projects));
        usort($projects, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return [
            'revenue' => round($income, 2), 'tradeRevenue' => round($revenue, 2), 'otherIncome' => round($other, 2),
            'expenses' => round($expenses, 2), 'profit' => $profit,
            'margin' => $income > 0 ? round($profit / $income * 100, 1) : null,
            'parts' => array_map(fn ($v) => round($v, 2), $parts),
            'months' => array_map(fn ($m) => array_map(fn ($v) => round($v, 2), $m), $months),
            'trades' => $trades, 'tradeCount' => count($trades), 'projects' => $projects,
            'overheads' => array_map(fn ($v) => round($v, 2), $overheads), 'incomplete' => $incomplete,
            // after the taxes, as the MZ goes on: MV 20 %, the net profit, the 5 % dividend, what is left — the period and its months
            'taxes' => ['profit' => $profit, 'mv' => $pl['taxTotal'], 'net' => $pl['netTotal'], 'dividend' => $pl['dividendTotal'], 'retained' => $pl['retainedTotal'],
                'months' => array_combine($pl['months'], array_map(fn ($m) => ['profit' => $pl['result'][$m], 'mv' => $pl['tax'][$m], 'net' => $pl['net'][$m],
                    'dividend' => $pl['dividend'][$m], 'retained' => $pl['retained'][$m]], $pl['months']))],
        ];
    }
}
