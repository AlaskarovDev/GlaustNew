<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Terms of the seller's invoice itself, both optional:
 *   - freight (NAVLUN) the seller bills on the invoice — part of what we owe it, spread over the lines by their Total;
 *   - a discount in percent of the goods — each line loses that percent of its own Total.
 * Line Total = seller's Total − discount + freight share; Unit Price = Total / Quantity. The seller's own figures are
 * kept (base_unit_price / base_total), so the terms can be changed or removed. Our commission and a logistics cost
 * spread by Total follow the new Totals.
 */
class FreightDiscount
{
    public function __construct(private LogisticsAllocator $allocator, private CommissionCalculator $commission) {}

    public function apply(Invoice $invoice, ?float $freight, ?float $percent): void
    {
        $freight = $freight ? round($freight, 2) : null;
        $percent = $percent ? round($percent, 4) : null;
        $items = $invoice->items()->orderBy('line_no')->orderBy('id')->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['freight_amount' => __('Fakturada məhsul sətri yoxdur.')]);
        }

        $base = $items->mapWithKeys(fn ($it) => [$it->id => (float) ($it->base_total ?? $it->total)])->all();
        $goods = round(array_sum($base), 2);
        $discountTotal = $percent ? round($goods * $percent / 100, 2) : 0.0;
        $discount = $percent ? $this->allocator->split($base, $discountTotal) : array_fill_keys(array_keys($base), 0.0);
        $share = $freight ? $this->allocator->split($base, $freight) : array_fill_keys(array_keys($base), 0.0);

        DB::transaction(function () use ($invoice, $items, $base, $discount, $share, $freight, $percent, $discountTotal) {
            $sum = 0.0;
            foreach ($items as $it) {
                $unit = (float) ($it->base_unit_price ?? $it->unit_price);
                if (! $freight && ! $percent) {   // back to the seller's own figures
                    $it->update(['total' => $base[$it->id], 'unit_price' => $unit, 'base_total' => null, 'base_unit_price' => null, 'freight' => null, 'discount' => null]);
                    $sum += $base[$it->id];

                    continue;
                }
                $total = round($base[$it->id] - $discount[$it->id] + $share[$it->id], 2);
                $qty = (float) $it->quantity;
                $it->update(['base_total' => $base[$it->id], 'base_unit_price' => $unit, 'total' => $total,
                    'unit_price' => $qty > 0 ? round($total / $qty, 4) : $unit,
                    'freight' => $freight ? $share[$it->id] : null, 'discount' => $percent ? $discount[$it->id] : null]);
                $sum += $total;
            }
            $sum = round($sum, 2);
            $invoice->update(['total' => $sum, 'total_azn' => $invoice->cbar_rate ? round($sum * (float) $invoice->cbar_rate, 2) : $invoice->total_azn,
                'freight_amount' => $freight, 'discount_percent' => $percent, 'discount_amount' => $percent ? $discountTotal : null]);

            // what follows the line Totals
            $invoice->refresh();
            if ($invoice->logistics_method === 'total' && $invoice->logistics_amount !== null) {
                $this->allocator->apply($invoice, $invoice->logistics_mode, 'total', $invoice->logistics_currency, (float) $invoice->logistics_amount);
            }
            if ($invoice->commission_rate !== null) {
                $this->commission->apply($invoice, (float) $invoice->commission_rate);
            }
        });
    }

    /** The goods before the terms (the seller's own Totals). */
    public static function goods(Invoice $invoice): float
    {
        return round((float) $invoice->items->sum(fn ($it) => (float) ($it->base_total ?? $it->total)), 2);
    }
}
