<?php

namespace App\Support;

use App\Models\Deal;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Support\Collection;

/**
 * Approximate (forecast) profit, AZN at today's CBAR: our commission on the seller invoices
 * (the margin we add on top of the purchase) minus the expenses booked on the deal (bank fees etc.).
 * Null while no commission has been applied yet.
 */
class ProjectForecast
{
    /** @param  Collection<int, Deal>  $deals  with invoices (type, status, commission_total, currency) and expenses_sum_amount_azn */
    public static function profit(Collection $deals): ?float
    {
        $rates = app(CurrencyRates::class);
        $today = $rates->today();
        $commission = null;
        foreach ($deals as $deal) {
            foreach ($deal->invoices as $inv) {
                if ($inv->type !== 'supplier' || $inv->status === 'cancelled' || $inv->commission_total === null) {
                    continue;
                }
                $rate = $rates->tryRate($inv->currency, $today);
                if ($rate !== null) {
                    $commission = ($commission ?? 0) + (float) $inv->commission_total * $rate;
                }
            }
        }
        if ($commission === null) {
            return null;
        }

        return round($commission - (float) $deals->sum('expenses_sum_amount_azn'), 2);
    }
}
