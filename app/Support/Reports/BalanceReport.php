<?php

namespace App\Support\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Deal;
use App\Services\Cbar\CurrencyRates;

/**
 * Balans (the company's «BALANS» sheet) at a day, in AZN — from recorded data:
 *   AKTİVLƏR   1 / 2 Əsas vəsaitlər — bought (net of VAT) / their wear (the amortisation booked)
 *              50 Kassa (the cash desk) · 51 Hesablaşma hesabı (AZN accounts) · 52 Valyuta hesabı (currency accounts at CBAR of the day)
 *              61 Verilmiş avanslar — paid to a seller (or logistics) for what has not come yet (no act / invoice by the day)
 *              62 Alıcılarla hesablaşmalar — sold (act done), not yet received
 *   PASSİVLƏR  60 Malsatanlarla hesablaşmalar — bought (act done) or logistics acted, not yet paid; a supplier's service
 *                 booked as «Hesablanıb» (with its counterparty) less what was paid it
 *              64 Alınmış avanslar — received from a buyer before the act
 *              80 Mənfəət və zərər — the year's result to the day (ProfitLossReport)
 *              68, 75, 85 … — the 1C figures given (Balans qalığı), the latest on or before the day
 *              88 Bölüşdürülməmiş mənfəət — the results of the years before, after their profit tax, less the dividends paid
 *                 (expense categories named «…dividend…»: the dividend and the tax withheld on it)
 * Every currency amount at CBAR of the day (the sheet's «Açıq hesab 61.2 / 64.2»: amount × the month-end rate).
 * «Fərq» = assets − the rest: the capital and whatever money moved outside the books.
 */
class BalanceReport
{
    public function __construct(private CurrencyRates $rates) {}

    /** @return array{date: string, assets: array, liabilities: array, assetsTotal: float, liabilitiesTotal: float, gap: float} */
    public function build(string $date): array
    {
        $rate = fn (string $cur) => $cur === 'AZN' ? 1.0 : $this->rates->tryRate($cur, $date);
        $line = fn (string $label) => ['label' => $label, 'value' => 0.0, 'items' => []];
        $assets = ['1' => $line(__('Əsas vəsaitlər')), '2' => $line(__('Əsas vəsaitlərin köhnəlməsi')), '50' => $line(__('Kassa')), '51' => $line(__('Hesablaşma hesabı')), '52' => $line(__('Valyuta hesabı')), '61' => $line(__('Verilmiş avanslar üzrə hesablaşmalar')),
            '62' => $line(__('Alıcılar və sifarişçilərlə hesablaşmalar'))];
        $liab = ['60' => $line(__('Malsatan və podratçılarla hesablaşmalar')), '64' => $line(__('Alınmış avanslar üzrə hesablaşmalar')),
            '80' => $line(__('Mənfəət və zərər')), '88' => $line(__('Bölüşdürülməmiş mənfəət (ödənilməmiş zərər)'))];
        $put = function (array &$side, string $code, float $amount, string $cur, string $text, ?string $url = null) use ($rate) {
            if (abs($amount) < 0.005) {
                return;
            }
            $r = $rate($cur);
            $azn = $r === null ? 0.0 : round($amount * $r, 2);
            $side[$code]['value'] += $azn;
            $side[$code]['items'][] = ['t' => $text, 'c' => num($amount).' '.$cur.($cur === 'AZN' ? '' : ' × '.($r ? rate_fmt($r) : '?').' CBAR'), 'v' => $azn, 'u' => $url];
        };

        // the money on the accounts
        foreach (BankAccount::orderBy('bank_name')->orderBy('name')->get() as $a) {
            $moves = (float) BankTransaction::where('bank_account_id', $a->id)->where('transaction_date', '<=', $date.' 23:59:59')
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as s")->value('s');
            $bal = ($a->opening_date === null || $a->opening_date->toDateString() <= $date ? (float) $a->opening_balance : 0.0) + $moves;
            $cash = mb_stripos($a->bank_name.' '.$a->name, 'kassa') !== false;   // the cash desk is a line of its own (50)
            $put($assets, $cash ? '50' : ($a->currency === 'AZN' ? '51' : '52'), round($bal, 2), $a->currency, $a->bank_name.' · '.$a->name);
        }

        // the Trades: what was paid and received by the day against what has come (the act) by it
        $deals = Deal::with(['supplierPayments', 'payments', 'logisticsActs.payments'])->get();
        $rows = collect(app(TradeReport::class)->rows($deals, $date))->groupBy(fn ($r) => $r['deal']->id);
        foreach ($deals as $deal) {
            $rs = $rows->get($deal->id);
            if (! $rs) {
                continue;
            }
            $first = $rs->first();
            $done = $rs->every(fn ($r) => ! $r['provisional'] && $r['BI'] !== null);   // every invoice has its act by the day
            $cur = $first['cur'];
            $saleCur = $first['saleCur'];
            $tag = 'Trade '.$deal->code;
            $url = route('deals.show', $deal);

            // the seller
            $owed = (float) $rs->sum('D');
            $paid = (float) $deal->supplierPayments->filter(fn ($p) => $p->payment_date->toDateString() <= $date)->sum('amount');
            if ($done) {
                $left = round($owed - $paid, 2);
                $left > 0 ? $put($liab, '60', $left, $cur, $tag.' · '.__('satıcıya borc'), $url) : $put($assets, '61', -$left, $cur, $tag.' · '.__('satıcıya artıq ödəniş'), $url);
            } else {
                $put($assets, '61', $paid, $cur, $tag.' · '.__('satıcıya avans'), $url);
            }

            // the buyer
            $sold = (float) $rs->sum('H');
            $got = (float) $deal->payments->where('currency', $saleCur)->filter(fn ($t) => $t->transaction_date->toDateString() <= $date)->sum('amount');
            if ($done) {
                $left = round($sold - $got, 2);
                $left > 0 ? $put($assets, '62', $left, $saleCur, $tag.' · '.__('alıcının borcu'), $url) : $put($liab, '64', -$left, $saleCur, $tag.' · '.__('alıcıdan artıq daxilolma'), $url);
            } else {
                $put($liab, '64', $got, $saleCur, $tag.' · '.__('alıcıdan avans'), $url);
            }

            // logistics: each act against its act's day — paid before the act, the money is an advance to the carrier (61), as 1C has it
            foreach ($deal->logisticsActs as $act) {
                $billed = ($act->act_date?->toDateString() ?? '9999') <= $date ? (float) $act->amount : 0.0;
                $paidAct = (float) $act->payments->filter(fn ($p) => $p->payment_date->toDateString() <= $date)->sum('act_amount');
                $left = round($billed - $paidAct, 2);
                $left > 0 ? $put($liab, '60', $left, $act->currency, $tag.' · '.__('logistikaya borc'), $url) : $put($assets, '61', -$left, $act->currency, $tag.' · '.__('logistikaya avans'), $url);
            }
        }

        // 1 / 2: the fixed assets bought (categories «Əsas vəsait»), net of the 18 % VAT, and their wear — the amortisation booked (227.10)
        $upTo = fn ($q) => $q->where(fn ($w) => $w->where('expense_date', '<=', $date.' 23:59:59'));
        foreach (\App\Models\Expense::with('category')->whereHas('category', fn ($q) => $q->where('pl_line', ProfitLossReport::ASSET))->where('status', '!=', 'accrued')->tap($upTo)->get() as $e) {
            $gross = (float) ($e->amount_azn ?? $e->amount);
            $put($assets, '1', round($gross / 1.18, 2), 'AZN', $e->category->name.' · '.azdate($e->expense_date).' · '.__('ƏDV xaric').' ('.num($gross).' / 1,18)');
        }
        foreach (\App\Models\Expense::with('category')->whereHas('category', fn ($q) => $q->where('pl_line', '227.10')->orWhere('name', 'like', '%mortizasiya%'))->tap($upTo)->get() as $e) {
            $put($assets, '2', -(float) ($e->amount_azn ?? $e->amount), 'AZN', $e->category->name.' · '.azdate($e->expense_date).($e->description ? ' · '.$e->description : ''));
        }

        // 60 / 61 beyond the Trades: a supplier's service booked as «Hesablanıb» against what has been paid it (by counterparty)
        $byParty = \App\Models\Expense::with('counterparty')->whereNotNull('counterparty_id')->whereNull('deal_id')->tap($upTo)->get()->groupBy('counterparty_id');
        foreach ($byParty as $items) {
            if (! $items->contains('status', 'accrued')) {
                continue;   // only what was booked as owed first
            }
            $owed = $items->where('status', 'accrued')->sum(fn ($e) => (float) ($e->amount_azn ?? $e->amount));
            $paid = $items->where('status', 'paid')->filter(fn ($e) => ($e->paid_at ?? $e->expense_date)->toDateString() <= $date)->sum(fn ($e) => (float) ($e->amount_azn ?? $e->amount));
            $left = round($owed - $paid, 2);
            $who = $items->first()->counterparty?->name ?? '—';
            $left > 0 ? $put($liab, '60', $left, 'AZN', $who.' · '.__('hesablanmış xidmət, ödənilməyib'))
                : $put($assets, '61', -$left, 'AZN', $who.' · '.__('xidmətə artıq ödəniş'));
        }

        // the 1C accounts the data cannot make (68 the budget, 75, 85 …): the latest figure given on or before the day
        $given = \App\Models\BalanceEntry::where('entry_date', '<=', $date)->orderBy('entry_date')->orderBy('id')->get()->groupBy('line')->map->last();
        foreach ($given->sortKeys() as $code => $e) {
            $liab[$code] ??= $line(__(\App\Models\BalanceEntry::LINES[$code] ?? $code));
            $liab[$code]['value'] += (float) $e->amount;
            $liab[$code]['items'][] = ['t' => __('1C üzrə qalıq').' · '.azdate($e->entry_date).' · '.$e->description, 'c' => null, 'v' => round((float) $e->amount, 2), 'u' => null];
        }
        if ($given->isNotEmpty()) {   // in the sheet's order: 60, 64, 68 …, 80, 85, 88
            uksort($liab, fn ($a, $b) => (float) $a <=> (float) $b);
        }

        // the result: this year to the day, and the years before
        $year = (int) substr($date, 0, 4);
        $pl = new ProfitLossReport;
        $now = $pl->build("{$year}-01", substr($date, 0, 7));
        $liab['80']['value'] = round(array_sum($now['result']), 2);
        $liab['80']['items'][] = ['t' => __('Mənfəət və zərər').': 01.01.'.$year.' — '.azdate($date), 'c' => null, 'v' => $liab['80']['value'], 'u' => route('analytics.show', ['profit', 'from' => "{$year}-01", 'to' => substr($date, 0, 7)])];
        $firstYear = (int) substr((string) (Deal::min('deal_date') ?? $date), 0, 4);
        // 88: the years before, after their profit tax, less what has been paid out of them (the dividend and its tax)
        for ($y = min($firstYear, $year); $y < $year; $y++) {
            $past = $pl->build("{$y}-01", "{$y}-12");
            $v = round(array_sum($past['result']), 2);
            $liab['88']['value'] += $v;
            $liab['88']['items'][] = ['t' => __('Mənfəət və zərər').' '.$y, 'c' => null, 'v' => $v, 'u' => route('analytics.show', ['profit', 'from' => "{$y}-01", 'to' => "{$y}-12"])];
            if (abs((float) $past['taxTotal']) >= 0.005) {
                $liab['88']['value'] -= (float) $past['taxTotal'];
                $liab['88']['items'][] = ['t' => __('Mənfəət vergisi').' '.$y.' (20 %)', 'c' => null, 'v' => -round((float) $past['taxTotal'], 2), 'u' => null];
            }
        }
        $paidOut = \App\Models\Expense::with('category')->whereHas('category', fn ($q) => $q->where('name', 'like', '%ividend%'))
            ->where('status', 'paid')->where(fn ($q) => $q->where('paid_at', '<=', $date.' 23:59:59')->orWhere(fn ($w) => $w->whereNull('paid_at')->where('expense_date', '<=', $date.' 23:59:59')))->get();
        foreach ($paidOut as $e) {
            $v = round((float) ($e->amount_azn ?? $e->amount), 2);
            $liab['88']['value'] -= $v;
            $liab['88']['items'][] = ['t' => $e->category->name.' · '.azdate($e->paid_at ?? $e->expense_date), 'c' => $e->description, 'v' => -$v, 'u' => null];
        }

        foreach ([&$assets, &$liab] as &$side) {
            foreach ($side as &$l) {
                $l['value'] = round($l['value'], 2);
            }
        }
        unset($side, $l);
        $at = round(array_sum(array_column($assets, 'value')), 2);
        $lt = round(array_sum(array_column($liab, 'value')), 2);

        return ['date' => $date, 'assets' => $assets, 'liabilities' => $liab, 'assetsTotal' => $at, 'liabilitiesTotal' => $lt, 'gap' => round($at - $lt, 2)];
    }
}
