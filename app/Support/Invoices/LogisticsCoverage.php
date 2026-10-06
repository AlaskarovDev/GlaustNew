<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Models\LogisticsAct;
use Illuminate\Support\Collection;

/**
 * How much of a seller invoice's planned logistics is already covered by logistics companies'
 * invoices (one or several companies, any currency): the rest is still to be invoiced / paid.
 * Logistics invoices are compared in the planned currency through the CBAR values stored on them.
 */
class LogisticsCoverage
{
    /** @return array{currency: ?string, planned: float, covered: float, left: float, count: int} */
    public static function for(Invoice $invoice, Collection $acts): array
    {
        $cur = $invoice->logistics_currency;
        $planned = (float) ($invoice->logistics_amount ?? 0);
        $mine = $acts->where('invoice_id', $invoice->id);
        $covered = round($mine->sum(fn (LogisticsAct $a) => self::in($a, $cur)), 2);

        return ['currency' => $cur, 'planned' => $planned, 'covered' => $covered, 'left' => round(max(0, $planned - $covered), 2), 'count' => $mine->count()];
    }

    /** A logistics invoice's amount in $currency (its own amount when the currency matches, else via its stored CBAR values). */
    public static function in(LogisticsAct $a, ?string $currency): float
    {
        return match (true) {
            ! $currency, $a->currency === $currency => (float) $a->amount,
            $currency === 'EUR' => (float) $a->amount_eur,
            $currency === 'RUB' => (float) $a->amount_rub,
            $currency === 'AZN' => (float) $a->amount_azn,
            default => (float) $a->amount_azn / max(1e-9, (float) (app(\App\Services\Cbar\CurrencyRates::class)->tryRate($currency, $a->docDate()) ?? 0)),
        };
    }
}
