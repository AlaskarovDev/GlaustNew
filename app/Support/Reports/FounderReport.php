<?php

namespace App\Support\Reports;

use App\Models\BankTransaction;
use App\Models\Deal;
use App\Models\Expense;
use App\Services\SupplierPaymentService;

/**
 * Təsisçi hesabatı — revenue, expenses and net profit at a glance, for a period, a project or a Trade.
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
    public function build(string $from, string $to, ?int $projectId = null, ?int $dealId = null): array
    {
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
            if ($r['AC'] === null || $r['BE'] === null) {
                $incomplete++;

                continue;
            }
            $rev = (float) $r['AC'];
            $p = ['purchase' => (float) $r['AA'], 'logistics' => (float) ($r['BC'] ?? 0), 'fees' => (float) ($r['AJ'] ?? 0) + (float) ($r['BB'] ?? 0), 'fx' => (float) ($r['AL'] ?? 0) + (float) ($r['AM'] ?? 0)];
            $profit = (float) $r['BE'];
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

        if ($company) {
            // the running expenses of the company: not of a Trade, not kept out of the P&L, not a transfer fee of a Trade
            foreach (Expense::with('category')->whereNull('deal_id')->whereBetween('expense_date', [$from, $to])->get() as $e) {
                if ($e->category?->name === SupplierPaymentService::FEE_CATEGORY || ProfitLossReport::lineOf($e->category) === null) {
                    continue;
                }
                $azn = (float) ($e->amount_azn ?? $e->amount);
                $name = $e->category?->name ?? __('Digər xərclər');
                $overheads[$name] = ($overheads[$name] ?? 0) + $azn;
                $parts['overhead'] += $azn;
                $m = $e->expense_date->format('Y-m');
                if (isset($months[$m])) {
                    $months[$m]['expenses'] += $azn;
                    $months[$m]['profit'] -= $azn;
                }
            }
            // other income: money in with an income category on the P&L (e.g. a deposit's interest)
            foreach (BankTransaction::with('category')->where('direction', 'in')->where('kind', 'regular')->whereNotNull('category_id')
                ->whereBetween('transaction_date', [$from, $to.' 23:59:59'])->get() as $t) {
                if (ProfitLossReport::lineOf($t->category) !== null) {
                    $v = (float) $t->cbar_amount_azn;
                    $other += $v;
                    $m = $t->transaction_date->format('Y-m');
                    if (isset($months[$m])) {
                        $months[$m]['revenue'] += $v;
                        $months[$m]['profit'] += $v;
                    }
                }
            }
        }

        $expenses = array_sum($parts);
        $income = $revenue + $other;
        arsort($overheads);
        $trades = array_values($trades);
        usort($trades, fn ($a, $b) => $b['profit'] <=> $a['profit']);
        $projects = array_values(array_map(fn ($p) => $p + ['count' => count($p['trades'])], $projects));
        usort($projects, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return [
            'revenue' => round($income, 2), 'tradeRevenue' => round($revenue, 2), 'otherIncome' => round($other, 2),
            'expenses' => round($expenses, 2), 'profit' => round($income - $expenses, 2),
            'margin' => $income > 0 ? round(($income - $expenses) / $income * 100, 1) : null,
            'parts' => array_map(fn ($v) => round($v, 2), $parts),
            'months' => array_map(fn ($m) => array_map(fn ($v) => round($v, 2), $m), $months),
            'trades' => $trades, 'tradeCount' => count($trades), 'projects' => $projects,
            'overheads' => array_map(fn ($v) => round($v, 2), $overheads), 'incomplete' => $incomplete,
        ];
    }
}
