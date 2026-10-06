<?php

namespace App\Support;

use App\Models\Deal;
use App\Models\Expense;

/**
 * A Trade's money per currency, for buying / selling currency against its obligations:
 *   due       — what the Trade still has to pay in the currency (seller, logistics, refunds to the buyer);
 *   acquired  — what was bought into the currency for this Trade (exchanges linked to it);
 *   spent     — what the Trade has paid out in the currency (payments, logistics, fees, its expenses);
 *   available — acquired − spent (bought for it and not used yet);
 *   to_buy    — due not covered by what is available;  surplus — bought beyond what is due.
 * Example: logistics 6 000 EUR due, 3 000 EUR bought → 3 000 still to buy; logistics then
 * invoiced and paid 2 750 → 250 EUR bought too much.
 */
class DealFinance
{
    /** @return array<string, array{currency: string, due: float, acquired: float, given: float, spent: float, available: float, to_buy: float, surplus: float}> */
    public static function currencies(Deal $deal): array
    {
        $deal->loadMissing(['currencyExchanges', 'supplierPayments', 'logisticsActs.payments', 'expenses']);
        $ob = DealObligations::for($deal);
        $rows = [];
        $add = function (string $cur, string $key, float $v) use (&$rows) {
            if (abs($v) < 0.005) {
                return;
            }
            $rows[$cur] ??= ['currency' => $cur, 'due' => 0.0, 'acquired' => 0.0, 'given' => 0.0, 'spent' => 0.0];
            $rows[$cur][$key] = round($rows[$cur][$key] + $v, 2);
        };

        foreach ($ob['payable'] as $cur => $v) {
            $add($cur, 'due', (float) $v);
        }
        foreach ($deal->currencyExchanges as $x) {
            if ($x->direction === 'buy') {
                $add($x->currency, 'acquired', (float) $x->amount);
                $add($x->counter_currency, 'given', (float) $x->counter_amount);
            } else {
                $add($x->currency, 'given', (float) $x->amount);
                $add($x->counter_currency, 'acquired', (float) $x->counter_amount);
            }
        }
        foreach ($deal->supplierPayments as $p) {
            $add($p->account_currency, 'spent', (float) $p->account_amount);
            $add($p->feeCurrency(), 'spent', (float) $p->fee_account_amount);
        }
        foreach ($deal->logisticsActs as $act) {
            foreach ($act->payments as $lp) {
                $add($lp->currency, 'spent', (float) $lp->amount + (float) $lp->fee_amount);
            }
        }
        $feeIds = $deal->supplierPayments->pluck('fee_expense_id')->merge($deal->logisticsActs->flatMap->payments->pluck('fee_expense_id'))->filter()->all();
        foreach ($deal->expenses->whereNotIn('id', $feeIds)->where('status', 'paid') as $e) {
            $add($e->currency, 'spent', (float) $e->amount);
        }

        foreach ($rows as $cur => $r) {
            $available = round($r['acquired'] - $r['spent'], 2);
            $rows[$cur]['available'] = $available;
            $rows[$cur]['to_buy'] = round(max(0, $r['due'] - max(0, $available)), 2);
            $rows[$cur]['surplus'] = $r['acquired'] > 0 ? round(max(0, $available - $r['due']), 2) : 0.0;
        }
        unset($rows['AZN']);   // the funding currency: nothing to buy into

        return $rows;
    }

    /** Every money movement of the Trade, newest first: [date, kind, text, currency, in, out, link]. */
    public static function movements(Deal $deal): array
    {
        $deal->loadMissing(['payments.account', 'supplierPayments.account', 'supplierPayments.feeAccount', 'logisticsActs.payments.account', 'logisticsActs.counterparty', 'expenses.category', 'currencyExchanges']);
        $out = [];
        $row = fn ($date, $kind, $text, $cur, $in, $outAmt, $tone) => compact('date', 'kind', 'text', 'cur', 'in', 'outAmt', 'tone');
        foreach ($deal->payments as $t) {
            $out[] = $row($t->transaction_date, __('Mədaxil'), ($deal->counterparty?->name ?? '').' · '.$t->account?->name, $t->currency, (float) $t->amount, null, 'success');
        }
        foreach ($deal->supplierPayments as $p) {
            $out[] = $row($p->payment_date, __('Satıcıya ödəniş'), money($p->amount, $p->currency).' · '.$p->account?->name, $p->account_currency, null, (float) $p->account_amount, 'danger');
            if ((float) $p->fee_account_amount) {
                $out[] = $row($p->payment_date, __('Bank komissiyası'), __('satıcıya köçürmə').' · '.($p->feeAccount?->name ?? $p->account?->name), $p->feeCurrency(), null, (float) $p->fee_account_amount, 'danger');
            }
        }
        foreach ($deal->logisticsActs as $a) {
            foreach ($a->payments as $lp) {
                $out[] = $row($lp->payment_date, __('Logistika ödənişi'), $a->label().' · '.($a->counterparty?->name ?? ''), $lp->currency, null, (float) $lp->amount, 'danger');
                if ((float) $lp->fee_amount) {
                    $out[] = $row($lp->payment_date, __('Bank komissiyası'), __('logistika köçürməsi'), $lp->currency, null, (float) $lp->fee_amount, 'danger');
                }
            }
        }
        $feeIds = $deal->supplierPayments->pluck('fee_expense_id')->merge($deal->logisticsActs->flatMap->payments->pluck('fee_expense_id'))->filter()->all();
        foreach ($deal->expenses->whereNotIn('id', $feeIds) as $e) {
            $out[] = $row($e->paid_at ?? $e->expense_date, __('Xərc'), trim(($e->category?->name ? $e->category->name.' · ' : '').$e->description).($e->status !== 'paid' ? ' ('.__('ödənilməyib').')' : ''), $e->currency, null, (float) $e->amount, 'danger');
        }
        foreach ($deal->currencyExchanges as $x) {
            $buy = $x->direction === 'buy';
            $out[] = $row($x->exchange_date, $buy ? __('Valyuta alışı') : __('Valyuta satışı'), money($x->counter_amount, $x->counter_currency).($buy ? ' → ' : ' ← ').money($x->amount, $x->currency),
                $buy ? $x->currency : $x->counter_currency, $buy ? (float) $x->amount : (float) $x->counter_amount, null, 'brand');
            $out[] = $row($x->exchange_date, $buy ? __('Valyuta alışı') : __('Valyuta satışı'), __('mübadilə üçün verilən'),
                $buy ? $x->counter_currency : $x->currency, null, $buy ? (float) $x->counter_amount : (float) $x->amount, 'muted');
        }
        usort($out, fn ($a, $b) => strcmp((string) optional($b['date'])->format('Y-m-d'), (string) optional($a['date'])->format('Y-m-d')));

        return $out;
    }
}
