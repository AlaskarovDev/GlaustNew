<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Our commission on a seller's invoice: line commission = line Total × rate / 100 (the sheet's
 * =H*0.035, with the rate chosen per invoice instead of a fixed 3.5%). Stored per line, rounded
 * to cents, so the column and its total always agree.
 */
class CommissionCalculator
{
    public function apply(Invoice $invoice, float $rate): void
    {
        DB::transaction(function () use ($invoice, $rate) {
            $sum = 0.0;
            foreach ($invoice->items()->get() as $item) {
                $fee = round((float) $item->total * $rate / 100, 2);
                $item->update(['commission' => $fee]);
                $sum += $fee;
            }
            $invoice->update(['commission_rate' => $rate, 'commission_total' => round($sum, 2), 'commission_updated_at' => now()]);
        });
    }

    public function clear(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice->items()->update(['commission' => null]);
            $invoice->update(['commission_rate' => null, 'commission_total' => null, 'commission_updated_at' => null]);
        });
    }
}
