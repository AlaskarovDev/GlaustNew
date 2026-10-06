<?php

namespace App\Support\Profit;

use App\Models\Deal;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Services\Cbar\CurrencyRates;
use App\Support\Finance\BankFee;
use Illuminate\Support\Collection;

/**
 * Profit of a Trade, one row per seller invoice (one shipment / "maşın"), in the order of the
 * company's ATF workbook (sheet ATF, columns noted as [XX]). Seller (e.g. Ellis) is paid in the
 * invoice currency (D, EUR), the buyer (e.g. Axios) pays the proforma (H, RUB). AZN throughout.
 *
 *  1. Forecast (invoice date K, forecast/CBAR rates N, O of the RUB step):
 *       P = D×N, Q = H×O, R = logistics, S = transfer fee  →  forecast = Q − P − R − S
 *  2. At the logistics act date (CBAR of the act date BJ, BK) — the operation closes with the last act:
 *       BL = D×BJ, BM = H×BK, BN = BM − BL, BO = act in AZN  →  BP = BN − BO
 *  3. Exchange differences to the operation (seller payment) date X (CBAR Y, Z) and fees:
 *       AB = D×Y, AC = H×Z, BQ = AC − BM, BR = BL − AB, BS = BC − BO (BC = logistics paid at CBAR of its day),
 *       AJ = transfer fee at CBAR, BB = logistics transfer fee
 *       net BT = BP + BQ + BR − BS − AJ − BB   (= AC − AB − BC − AJ − BB, the sheet's AQ)
 *  4. Bank: the bank's rates against CBAR on the money that moved (RUB in, EUR out, logistics) [AL, AM]
 *       and other expenses booked on the Trade  →  final = BT − bank differences − expenses  [BE]
 *
 * Deal-level money (seller payments, incoming payments, expenses, acts without an invoice) is shared
 * between the invoices by their weight (D in AZN), as the sheet shares a combined transfer fee.
 * Whatever is not paid yet is valued at today's CBAR and the row is marked as an estimate.
 */
class ProfitCalculator
{
    private array $memo = [];

    public function __construct(private CurrencyRates $rates) {}

    /** AZN per 1 unit at CBAR of $date; null when CBAR has no rate for it. */
    public function rate(?string $currency, $date): ?float
    {
        if (! $currency || ! $date) {
            return null;
        }
        $currency = strtoupper($currency);
        if ($currency === 'AZN') {
            return 1.0;
        }
        $day = $this->rates->normalize($date);
        if ($day->gt($this->rates->today())) {
            $day = $this->rates->today();
        }

        return $this->memo[$currency.$day->format('Ymd')] ??= $this->rates->tryRate($currency, $day);
    }

    public function today()
    {
        return $this->rates->today();
    }

    /** @return array{deal: Deal, rows: array<int, array>, totals: array} */
    public function deal(Deal $deal): array
    {
        $deal->loadMissing(['invoices', 'salesDocuments', 'supplierPayments', 'payments', 'expenses', 'logisticsActs.payments']);
        $invoices = $deal->invoices->where('type', 'supplier')->where('status', '!=', 'cancelled')->sortBy([['invoice_date', 'asc'], ['id', 'asc']])->values();

        $weights = [];
        foreach ($invoices as $inv) {
            $weights[$inv->id] = (float) ($inv->total_azn ?: $inv->total);
        }
        $weightSum = array_sum($weights) ?: 1;

        $saleOf = fn (Invoice $inv): ?SalesDocument => $deal->salesDocuments->where('source_invoice_id', $inv->id)->firstWhere('kind', 'proforma')
            ?? $deal->salesDocuments->where('source_invoice_id', $inv->id)->firstWhere('kind', 'commercial');
        $saleWeights = [];
        foreach ($invoices as $inv) {
            $saleWeights[$inv->id] = ($s = $saleOf($inv)) ? $s->grandTotal() : 0;
        }
        $saleWeightSum = array_sum($saleWeights) ?: 1;

        $feeExpenseIds = $deal->supplierPayments->pluck('fee_expense_id')
            ->merge($deal->logisticsActs->flatMap->payments->pluck('fee_expense_id'))->filter()->all();
        $otherExpenses = (float) $deal->expenses->whereNotIn('id', $feeExpenseIds)->sum('amount_azn');
        $incomingDiff = (float) $deal->payments->sum(fn ($t) => $t->exchangeDifference()); // + = better than CBAR

        $rows = [];
        foreach ($invoices as $inv) {
            $share = $weights[$inv->id] / $weightSum;
            $acts = $deal->logisticsActs->where('invoice_id', $inv->id)->values();
            $sharedActs = $deal->logisticsActs->whereNull('invoice_id');
            $rows[] = $this->row($inv, $saleOf($inv), $share, ($saleWeights[$inv->id] ?? 0) / $saleWeightSum, $deal, $acts, $sharedActs, $otherExpenses, $incomingDiff);
        }

        return ['deal' => $deal, 'rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /** @return array{project: Project, deals: array<int, array>, totals: array} */
    public function project(Project $project): array
    {
        $deals = $project->deals()->with(['invoices', 'salesDocuments', 'supplierPayments', 'payments', 'expenses', 'logisticsActs.payments', 'counterparty', 'supplier'])
            ->orderBy('deal_date')->orderBy('id')->get();
        $out = $deals->map(fn ($d) => $this->deal($d))->all();

        return ['project' => $project, 'deals' => $out, 'totals' => $this->totals(array_merge(...array_map(fn ($d) => $d['rows'], $out ?: [['rows' => []]])))];
    }

    private function row(Invoice $inv, ?SalesDocument $sale, float $share, float $saleShare, Deal $deal, Collection $acts, Collection $sharedActs, float $otherExpenses, float $incomingDiff): array
    {
        $D = (float) $inv->total;
        $cur = $inv->currency;
        $H = $sale ? $sale->grandTotal() : null;
        $saleCur = $sale?->currency;
        $today = $this->today();
        $row = ['invoice' => $inv, 'sale' => $sale, 'D' => $D, 'cur' => $cur, 'H' => $H, 'saleCur' => $saleCur, 'share' => $share,
            'forecast' => null, 'act' => null, 'settle' => null, 'bank' => null, 'estimated' => false, 'notes' => []];

        /* 1. Forecast */
        if ($inv->fx_base_azn && $inv->fx_target_azn && $H !== null) {
            $N = (float) $inv->fx_base_azn;
            $O = (float) $inv->fx_target_azn;
            $P = $D * $N;
            $Q = $H * $O;
            $R = 0.0;
            if ($inv->logistics_amount) {
                $lc = $inv->logistics_currency;
                $lr = $lc === $cur ? $N : ($lc === $saleCur ? $O : $this->rate($lc, $inv->fx_date));
                $R = (float) $inv->logistics_amount * ($lr ?? 0);
            }
            $fee = BankFee::for($cur, $D, $N, $cur === 'EUR' ? $N : $this->rate('EUR', $inv->fx_date));
            $S = $fee ? $fee['amount'] * $N : 0.0;
            $row['forecast'] = ['date' => $inv->fx_date, 'source' => $inv->fx_source, 'N' => $N, 'O' => $O, 'P' => $P, 'Q' => $Q, 'R' => $R,
                'fee' => $fee['amount'] ?? 0, 'S' => $S, 'profit' => $Q - $P - $R - $S,
                'commission' => $inv->commission_total !== null ? (float) $inv->commission_total * $N : null];
        } elseif ($H === null) {
            $row['notes'][] = __('Alıcı üçün proforma hələ yoxdur — satış məbləği (H) bilinmir.');
        }

        /* logistics acts of this invoice (+ its share of acts not tied to an invoice) */
        $actRows = $acts->map(fn (LogisticsAct $a) => ['act' => $a, 'k' => 1.0])
            ->merge($sharedActs->map(fn (LogisticsAct $a) => ['act' => $a, 'k' => $share]))->values();

        // planned logistics not yet invoiced by any logistics company (e.g. a second carrier still to come)
        $logLeft = $acts->isNotEmpty() ? \App\Support\Invoices\LogisticsCoverage::for($inv, $deal->logisticsActs)['left'] : 0.0;

        /* 2. At the act date */
        if ($actRows->isNotEmpty() && $H !== null) {
            $last = $actRows->max(fn ($r) => ($r['act']->act_date ?? $r['act']->docDate())->format('Y-m-d')); // the act's date, else its invoice's
            $BJ = $this->rate($cur, $last);
            $BK = $this->rate($saleCur, $last);
            if ($BJ !== null && $BK !== null) {
                $BL = $D * $BJ;
                $BM = $H * $BK;
                $BO = $actRows->sum(fn ($r) => (float) $r['act']->amount_azn * $r['k'])
                    + ($logLeft > 0.01 ? $logLeft * ($this->rate($inv->logistics_currency, $last) ?? 0) : 0);
                $row['act'] = ['date' => \Carbon\Carbon::parse($last), 'numbers' => $actRows->map(fn ($r) => $r['act']->label())->unique()->implode(', '),
                    'amounts' => $actRows->groupBy(fn ($r) => $r['act']->currency)->map(fn ($g) => $g->sum(fn ($r) => (float) $r['act']->amount * $r['k']))->all(),
                    'BJ' => $BJ, 'BK' => $BK, 'BL' => $BL, 'BM' => $BM, 'BN' => $BM - $BL, 'BO' => $BO, 'BP' => $BM - $BL - $BO];
            } else {
                $row['notes'][] = __('Akt tarixinə CBAR kursu tapılmadı.');
            }
        }

        /* 3. Seller payment (operation date), exchange differences and fees */
        $paid = $deal->supplierPayments->map(fn ($p) => [
            'p' => $p,
            'amount' => (float) $p->amount * $share,
            'azn' => (float) $p->amount * (float) $p->cbar_rate * $share,
            'z' => $p->account_currency === $saleCur ? (float) $p->cbar_account_rate : $this->rate($saleCur, $p->payment_date),
        ]);
        if ($paid->isNotEmpty() && $H !== null) {
            $paidAmount = $paid->sum('amount');
            $AB = $paid->sum('azn');
            $zWeighted = $paidAmount > 0 ? $paid->sum(fn ($x) => $x['z'] * $x['amount']) / $paidAmount : null;
            $Y = $paidAmount > 0 ? $AB / $paidAmount : null;
            $estimated = false;
            $left = $D - $paidAmount;
            $Z = $zWeighted;
            if ($left > 0.01) {                       // not fully paid yet: the rest at today's CBAR
                $estimated = true;
                $AB += $left * ($this->rate($cur, $today) ?? 0);
                $Z = ($zWeighted * $paidAmount + ($this->rate($saleCur, $today) ?? 0) * $left) / $D;
            }
            $AC = $H * $Z;
            $AJ = $deal->supplierPayments->sum(fn ($p) => (float) $p->fee_amount * (float) $p->cbar_rate) * $share;

            // logistics: paid part at CBAR of each payment day, the rest of the acts (or the forecast) at today's CBAR
            $BC = 0.0;
            $BB = 0.0;
            $logDiff = 0.0;
            if ($actRows->isNotEmpty()) {
                if ($logLeft > 0.01) {
                    $estimated = true;
                    $BC += $logLeft * ($this->rate($inv->logistics_currency, $today) ?? 0);
                    $row['notes'][] = __('Planlaşdırılan logistikanın :v1 hissəsi hələ invoys edilməyib — proqnoz kimi daxil edilib.', ['v1' => money($logLeft, $inv->logistics_currency)]);
                }
                foreach ($actRows as $r) {
                    $a = $r['act'];
                    $BC += $a->payments->sum(fn ($lp) => (float) $lp->act_amount * (float) $lp->cbar_act_rate) * $r['k'];
                    $BB += $a->payments->sum(fn ($lp) => (float) $lp->fee_azn) * $r['k'];
                    $logDiff += $a->payments->sum(fn ($lp) => (float) $lp->difference_azn) * $r['k'];
                    if ($a->remaining() > 0.01) {
                        $estimated = true;
                        $BC += $a->remaining() * ($this->rate($a->currency, $today) ?? 0) * $r['k'];
                    }
                }
            } elseif ($inv->logistics_amount) {
                $estimated = true;
                $BC = (float) $inv->logistics_amount * ($this->rate($inv->logistics_currency, $today) ?? 0);
                $row['notes'][] = __('Logistika aktı hələ yoxdur — logistika proqnoz məbləği ilə, bugünkü CBAR kursu ilə götürülüb.');
            }

            $net = $AC - $AB - $BC - $AJ - $BB;
            $settle = ['date' => $paid->max(fn ($x) => $x['p']->payment_date->format('Y-m-d')), 'paid' => $paidAmount, 'left' => max(0, $left),
                'Y' => $Y, 'Z' => $Z, 'AB' => $AB, 'AC' => $AC, 'AJ' => $AJ, 'BC' => $BC, 'BB' => $BB, 'BT' => $net, 'estimated' => $estimated];
            if ($row['act']) {
                $settle['BQ'] = $AC - $row['act']['BM'];
                $settle['BR'] = $row['act']['BL'] - $AB;
                $settle['BS'] = $BC - $row['act']['BO'];
            }
            $row['settle'] = $settle;
            $row['estimated'] = $estimated;

            /* 4. Bank rates against CBAR, other expenses */
            $supplierDiff = $deal->supplierPayments->sum(fn ($p) => (float) $p->difference_azn) * $share; // + = paid more than CBAR
            $incoming = $incomingDiff * $saleShare;                                                       // + = got more than CBAR
            $expenses = $otherExpenses * $share;
            $bankTotal = $incoming - $supplierDiff - $logDiff;
            $row['bank'] = ['incoming' => $incoming, 'supplier' => -$supplierDiff, 'logistics' => -$logDiff, 'total' => $bankTotal,
                'expenses' => $expenses, 'final' => $net + $bankTotal - $expenses];
        } elseif ($H !== null) {
            $row['notes'][] = __('Satıcıya ödəniş hələ edilməyib — xalis mənfəət ödənişdən sonra hesablanır.');
        }

        $row['stage'] = $row['bank'] ? ($row['estimated'] ? 'settling' : 'closed') : ($row['act'] ? 'act' : ($row['forecast'] ? 'forecast' : 'nosale'));
        $row['best'] = $row['bank']['final'] ?? $row['act']['BP'] ?? $row['forecast']['profit'] ?? null;

        return $row;
    }

    /** Sums of each stage over rows that reached it. */
    public function totals(array $rows): array
    {
        $sum = fn (string $stage, string $key) => array_sum(array_map(fn ($r) => $r[$stage][$key] ?? 0, array_filter($rows, fn ($r) => $r[$stage] !== null)));
        $count = fn (string $stage) => count(array_filter($rows, fn ($r) => $r[$stage] !== null));

        return [
            'rows' => count($rows),
            'D' => array_sum(array_column($rows, 'D')),
            'forecast' => ['count' => $count('forecast'), 'P' => $sum('forecast', 'P'), 'Q' => $sum('forecast', 'Q'), 'R' => $sum('forecast', 'R'), 'S' => $sum('forecast', 'S'), 'profit' => $sum('forecast', 'profit')],
            'act' => ['count' => $count('act'), 'BL' => $sum('act', 'BL'), 'BM' => $sum('act', 'BM'), 'BN' => $sum('act', 'BN'), 'BO' => $sum('act', 'BO'), 'BP' => $sum('act', 'BP')],
            'settle' => ['count' => $count('settle'), 'AB' => $sum('settle', 'AB'), 'AC' => $sum('settle', 'AC'), 'BQ' => $sum('settle', 'BQ'), 'BR' => $sum('settle', 'BR'), 'BS' => $sum('settle', 'BS'),
                'AJ' => $sum('settle', 'AJ'), 'BC' => $sum('settle', 'BC'), 'BB' => $sum('settle', 'BB'), 'BT' => $sum('settle', 'BT')],
            'bank' => ['count' => $count('bank'), 'total' => $sum('bank', 'total'), 'expenses' => $sum('bank', 'expenses'), 'final' => $sum('bank', 'final')],
            'best' => array_sum(array_map(fn ($r) => $r['best'] ?? 0, $rows)),
            'reached' => count(array_filter($rows, fn ($r) => $r['best'] !== null)),
            'estimated' => (bool) array_filter($rows, fn ($r) => $r['estimated']),
        ];
    }
}
