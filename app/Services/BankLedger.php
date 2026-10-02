<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes bank movements with their official CBAR valuation.
 *
 * Regular movement: cbar_rate = CBAR(currency, date); applied_rate = CBAR or the
 * bank's actual rate entered by the user; amount_azn at the applied rate,
 * cbar_amount_azn at the official one (the difference is the exchange result).
 *
 * Transfer / conversion: two linked rows (out + in) sharing transfer_group; each
 * leg is valued at its own CBAR rate. A conversion's result is
 * in.cbar_amount_azn - out.cbar_amount_azn.
 *
 * @throws \App\Services\Cbar\RateUnavailable when a rate is missing — never guessed.
 */
class BankLedger
{
    public function __construct(private CurrencyRates $rates) {}

    public function record(BankAccount $account, array $data, ?float $appliedRate = null): BankTransaction
    {
        $cbar = $this->rates->rate($account->currency, $data['transaction_date']);
        $applied = $account->currency === 'AZN' ? 1.0 : ($appliedRate ?: $cbar);
        $amount = round((float) $data['amount'], 2);

        return BankTransaction::create(array_merge($data, [
            'bank_account_id' => $account->id,
            'currency' => $account->currency,
            'kind' => 'regular',
            'amount' => $amount,
            'cbar_rate' => $cbar,
            'applied_rate' => $applied,
            'amount_azn' => round($amount * $applied, 2),
            'cbar_amount_azn' => round($amount * $cbar, 2),
            'created_by' => auth()->id(),
        ]));
    }

    public function update(BankTransaction $tx, array $data, ?float $appliedRate = null): BankTransaction
    {
        $account = $tx->account;
        $cbar = $this->rates->rate($account->currency, $data['transaction_date']);
        $applied = $account->currency === 'AZN' ? 1.0 : ($appliedRate ?: $cbar);
        $amount = round((float) $data['amount'], 2);

        $tx->update(array_merge($data, [
            'amount' => $amount,
            'cbar_rate' => $cbar,
            'applied_rate' => $applied,
            'amount_azn' => round($amount * $applied, 2),
            'cbar_amount_azn' => round($amount * $cbar, 2),
        ]));

        return $tx;
    }

    /** @return array{0: BankTransaction, 1: BankTransaction} [out, in] */
    public function transfer(BankAccount $from, BankAccount $to, string $date, float $amountOut, float $amountIn, array $common = []): array
    {
        $outRate = $this->rates->rate($from->currency, $date);
        $inRate = $this->rates->rate($to->currency, $date);
        $kind = $from->currency === $to->currency ? 'transfer' : 'conversion';
        $group = (string) Str::uuid();
        $amountOut = round($amountOut, 2);
        $amountIn = $kind === 'transfer' ? $amountOut : round($amountIn, 2);

        return DB::transaction(function () use ($from, $to, $date, $amountOut, $amountIn, $common, $outRate, $inRate, $kind, $group) {
            $base = array_merge($common, ['transaction_date' => $date, 'kind' => $kind, 'transfer_group' => $group, 'created_by' => auth()->id()]);
            $out = BankTransaction::create($base + [
                'bank_account_id' => $from->id, 'direction' => 'out', 'currency' => $from->currency, 'amount' => $amountOut,
                'cbar_rate' => $outRate, 'applied_rate' => $outRate,
                'amount_azn' => round($amountOut * $outRate, 2), 'cbar_amount_azn' => round($amountOut * $outRate, 2),
            ]);
            $in = BankTransaction::create($base + [
                'bank_account_id' => $to->id, 'direction' => 'in', 'currency' => $to->currency, 'amount' => $amountIn,
                'cbar_rate' => $inRate, 'applied_rate' => $inRate,
                'amount_azn' => round($amountIn * $inRate, 2), 'cbar_amount_azn' => round($amountIn * $inRate, 2),
            ]);

            return [$out, $in];
        });
    }

    /** Deletes a movement; a transfer leg takes its counterpart with it. */
    public function delete(BankTransaction $tx): int
    {
        return DB::transaction(function () use ($tx) {
            if ($tx->transfer_group) {
                $legs = BankTransaction::where('transfer_group', $tx->transfer_group)->get();
                $legs->each->delete();

                return $legs->count();
            }
            $tx->delete();

            return 1;
        });
    }
}
