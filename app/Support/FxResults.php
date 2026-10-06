<?php

namespace App\Support;

use App\Models\CurrencyExchange;
use App\Models\Deal;
use Illuminate\Support\Collection;

/**
 * Exchange-rate results of Trades (and of a project's own currency exchanges): wherever money moved at a
 * bank rate other than CBAR, in AZN, per currency. + = gain (got more / paid less than CBAR), − = expense.
 *   currency exchanges   — bank rate vs CBAR on the counter amount (e.g. RUB sold below CBAR: expense);
 *   incoming payments    — booked at the bank's rate vs CBAR;
 *   seller / logistics payments — paid from an account in another currency at the bank's cross rate.
 */
class FxResults
{
    /** @return array{rows: list<array>, currencies: array<string, array{gain: float, loss: float, net: float}>, net: float} */
    public static function for(Collection $deals, ?int $projectId = null): array
    {
        $rows = [];
        $add = function ($date, string $kind, string $cur, string $text, float $azn, ?string $trade) use (&$rows) {
            if (abs($azn) >= 0.005) {
                $rows[] = ['date' => $date, 'kind' => $kind, 'cur' => $cur, 'text' => $text, 'azn' => round($azn, 2), 'trade' => $trade];
            }
        };

        $deals = new \Illuminate\Database\Eloquent\Collection($deals->all());
        $deals->loadMissing(['payments', 'supplierPayments', 'logisticsActs.payments']);
        $exchanges = CurrencyExchange::whereIn('deal_id', $deals->pluck('id'))
            ->when($projectId, fn ($q) => $q->orWhere(fn ($w) => $w->where('project_id', $projectId)->whereNull('deal_id')))->with('deal:id,code')->get();
        foreach ($exchanges as $x) {
            $buy = $x->direction === 'buy';
            $add($x->exchange_date, $buy ? __('Valyuta alışı') : __('Valyuta satışı'), $x->currency,
                money($x->amount, $x->currency).' · '.__('bank kursu').' '.rate_fmt($x->bank_rate).' / CBAR '.rate_fmt($x->cbar_cross), -(float) $x->difference_azn, $x->deal?->code);
        }
        foreach ($deals as $deal) {
            /** @var Deal $deal */
            foreach ($deal->payments->where('currency', '!=', 'AZN') as $t) {
                $add($t->transaction_date, __('Mədaxil'), $t->currency, money($t->amount, $t->currency).' · '.__('bank kursu ilə'), $t->exchangeDifference(), $deal->code);
            }
            foreach ($deal->supplierPayments as $p) {
                $add($p->payment_date, __('Satıcıya ödəniş'), (string) ($p->account_currency ?? $p->currency), money($p->amount, $p->currency).' · '.__('bank kursu ilə'), -(float) $p->difference_azn, $deal->code);
                if ($p->fee_bank_rate) {   // its bank fee converted at the bank's rate
                    $add($p->payment_date, __('Bank komissiyası'), (string) $p->fee_account_currency, money($p->fee_amount, $p->currency).' · '.__('bank kursu').' '.rate_fmt($p->fee_bank_rate), -(float) $p->fee_difference_azn, $deal->code);
                }
            }
            foreach ($deal->logisticsActs->flatMap->payments as $lp) {
                $add($lp->payment_date, __('Logistika ödənişi'), (string) $lp->currency, money($lp->amount, $lp->currency).' · '.__('bank kursu ilə'), -(float) $lp->difference_azn, $deal->code);
            }
        }
        usort($rows, fn ($a, $b) => strcmp(self::day($a['date']), self::day($b['date'])));

        $cur = [];
        foreach ($rows as $r) {
            $c = &$cur[$r['cur']];
            $c ??= ['gain' => 0.0, 'loss' => 0.0, 'net' => 0.0];
            if ($r['azn'] > 0) {
                $c['gain'] = round($c['gain'] + $r['azn'], 2);
            } else {
                $c['loss'] = round($c['loss'] - $r['azn'], 2);
            }
            $c['net'] = round($c['gain'] - $c['loss'], 2);
            unset($c);
        }
        ksort($cur);

        return ['rows' => $rows, 'currencies' => $cur, 'net' => round(array_sum(array_column($cur, 'net')), 2)];
    }

    private static function day($d): string
    {
        return $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d;
    }
}
