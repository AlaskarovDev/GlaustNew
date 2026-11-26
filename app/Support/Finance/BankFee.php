<?php

namespace App\Support\Finance;

use App\Models\BankFeeRule;

/**
 * The bank's fee on an outgoing transfer: percent of the amount kept within a minimum and a
 * maximum, by the rule in force on the payment's date for the paying bank (Tənzimləmələr → Bank komissiyası; before any, config
 * glaust.bank_fees). A currency without its own rule uses the EUR rule with its limits converted at the CBAR rates
 * of the day (e.g. RUB: 25–300 EUR in roubles).
 */
class BankFee
{
    /**
     * @param  float  $azn  AZN per 1 unit of $currency (CBAR of the day)
     * @param  float|null  $eurAzn  AZN per 1 EUR (CBAR of the day), for converting the EUR rule
     * @param  \DateTimeInterface|string|null  $date  the payment's date: which rule is in force (today when null)
     * @param  string|null  $bank  the paying account's bank: its own rule wins over the rule for every bank
     * @return array{percent: float, minimum: float, maximum: ?float, amount: float}|null  amounts in $currency
     */
    public static function for(string $currency, float $amount, float $azn, ?float $eurAzn, \DateTimeInterface|string|null $date = null, ?string $bank = null): ?array
    {
        if ($rule = BankFeeRule::inForce($currency, $date, $bank)) {
            $factor = 1.0;
        } elseif ($eurAzn && ($rule = BankFeeRule::inForce('EUR', $date, $bank))) {
            $factor = $eurAzn / $azn; // EUR limits -> this currency
        } else {
            return null;
        }
        $min = round((float) $rule['minimum'] * $factor, 2);
        $max = isset($rule['maximum']) ? round((float) $rule['maximum'] * $factor, 2) : null;
        $fee = max($amount * (float) $rule['percent'] / 100, $min);
        if ($max !== null) {
            $fee = min($fee, $max);
        }

        return ['percent' => (float) $rule['percent'], 'minimum' => $min, 'maximum' => $max, 'amount' => round($fee, 2)];
    }
}
