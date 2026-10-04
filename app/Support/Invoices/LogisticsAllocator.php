<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts the logistics cost onto the lines of a seller's invoice.
 *
 * total:    line share = line Total / invoice Total × amount  — the company's sheet formula
 *           =H4*$I$12/$H$12. Shares are rounded to cents and the rounding remainder goes to
 *           the largest line, so the lines always add up to the amount entered.
 * per_item: the amount typed for each line; every line in the same currency.
 *
 * Amounts in another currency are converted to the invoice currency with the CBAR rates of
 * the invoice date (rate = AZN per unit of entered currency / AZN per unit of invoice currency).
 *
 * @throws ValidationException (also when a CBAR rate is missing — no guessed rates)
 */
class LogisticsAllocator
{
    public function __construct(private CurrencyRates $rates) {}

    public function apply(Invoice $invoice, string $mode, string $method, string $currency, ?float $amount, array $perItem = []): void
    {
        $items = $invoice->items()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['logistics' => __('Fakturada məhsul sətri yoxdur.')]);
        }

        $factor = $this->factor($currency, $invoice);

        if ($method === 'total') {
            if ($amount === null || $amount < 0) {
                throw ValidationException::withMessages(['logistics_amount' => __('Logistika xərcinin məbləğini daxil edin.')]);
            }
            $original = $this->split($items->pluck('total', 'id')->map(fn ($v) => (float) $v)->all(), $amount);
        } else {
            $original = [];
            foreach ($items as $item) {
                $v = $perItem[$item->id] ?? null;
                if ($v === null || $v === '' || ! is_numeric($v) || $v < 0) {
                    throw ValidationException::withMessages(["items.{$item->id}" => __('Sətir :v1 (:v2) üçün xərci daxil edin (0 ola bilər).', ['v1' => $item->line_no, 'v2' => $item->description])]);
                }
                $original[$item->id] = round((float) $v, 2);
            }
            $amount = round(array_sum($original), 2);
        }

        // Converted amounts also get the remainder treatment so they add up exactly.
        $converted = $this->split($original, round($amount * $factor, 2));

        DB::transaction(function () use ($invoice, $items, $original, $converted, $mode, $method, $currency, $amount, $factor) {
            foreach ($items as $item) {
                $item->update(['logistics_original' => $original[$item->id], 'logistics' => $converted[$item->id]]);
            }
            $invoice->update([
                'logistics_mode' => $mode, 'logistics_method' => $method, 'logistics_currency' => $currency,
                'logistics_amount' => $amount, 'logistics_rate' => $factor, 'logistics_total' => array_sum($converted),
                'logistics_updated_at' => now(),
            ]);
        });
    }

    public function clear(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice->items()->update(['logistics_original' => null, 'logistics' => null]);
            $invoice->update(['logistics_mode' => null, 'logistics_method' => null, 'logistics_currency' => null, 'logistics_amount' => null,
                'logistics_rate' => null, 'logistics_total' => null, 'logistics_updated_at' => null]);
        });
    }

    /** entered currency -> invoice currency, CBAR of the invoice date */
    public function factor(string $currency, Invoice $invoice): float
    {
        if ($currency === $invoice->currency) {
            return 1.0;
        }
        try {
            return round($this->rates->rate($currency, $invoice->invoice_date) / $this->rates->rate($invoice->currency, $invoice->invoice_date), 8);
        } catch (\App\Services\Cbar\RateUnavailable $e) {
            throw ValidationException::withMessages(['logistics_currency' => $e->getMessage().__(' Logistika yadda saxlanmadı.')]);
        }
    }

    /**
     * Split $amount over keys in proportion to $weights (cents), remainder to the largest weight.
     *
     * @param  array<int|string, float>  $weights
     * @return array<int|string, float>
     */
    public function split(array $weights, float $amount): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            $out = array_fill_keys(array_keys($weights), 0.0);
            $out[array_key_first($weights)] = round($amount, 2);

            return $out;
        }
        $out = [];
        foreach ($weights as $k => $w) {
            $out[$k] = round($w * $amount / $sum, 2);
        }
        $diff = round($amount - array_sum($out), 2);
        if (abs($diff) >= 0.01) {
            $largest = array_search(max($weights), $weights, true);
            $out[$largest] = round($out[$largest] + $diff, 2);
        }

        return $out;
    }
}
