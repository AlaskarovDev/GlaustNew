<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reached only through a tenant-scoped Invoice.
 *
 * Computed columns follow the company's sheet (H = total, I = logistics, K = commission, E = quantity):
 *   UNIT PRICE+LOG      J = (I + H) / E
 *   TOTAL PRICE CCL EUR M = H + I + K
 *   UNIT PRICE CCL EUR  L = M / E
 *   UNIT PRICE RUR      N = L × rate          (rate = D18 / D19, see RubConverter)
 *   TOTAL PRICE RUR     O = N × E
 *   UNIT PRICE RUR      P = ROUND(N, 2)       (the yellow columns)
 *   TOTAL PRICE RUR     Q = P × E
 * Each is null until the inputs it needs have been entered.
 */
class InvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:4', 'total' => 'decimal:2', 'logistics_original' => 'decimal:2', 'logistics' => 'decimal:2',
            'commission' => 'decimal:2', 'extra' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The line's logistics share unrounded, as the sheet's =H*$I$12/$H$12 keeps it. The stored
     * `logistics` is rounded to cents (so the column adds up); computing with it would move
     * TOTAL PRICE RUR by tenths of a rouble.
     */
    public function logisticsExact(): ?float
    {
        if ($this->logistics === null) {
            return null;
        }
        $inv = $this->invoice;
        if ($inv?->logistics_method === 'total' && (float) $inv->total > 0) {
            return (float) $this->total * (float) $inv->logistics_total / (float) $inv->total;
        }

        return $inv?->logistics_rate !== null && $this->logistics_original !== null
            ? (float) $this->logistics_original * (float) $inv->logistics_rate
            : (float) $this->logistics;
    }

    public function unitPriceLog(): ?float
    {
        $i = $this->logisticsExact();

        return $i === null || (float) $this->quantity <= 0 ? null : ((float) $this->total + $i) / (float) $this->quantity;
    }

    /** M = H + I + K, unrounded like the sheet (displayed with 2 decimals). */
    public function totalCcl(): ?float
    {
        $i = $this->logisticsExact();

        return $i === null || $this->commission === null ? null : (float) $this->total + $i + (float) $this->commission;
    }

    public function unitPriceCcl(): ?float
    {
        $m = $this->totalCcl();

        return $m === null || (float) $this->quantity <= 0 ? null : $m / (float) $this->quantity;
    }

    private function rubRate(): ?float
    {
        return $this->invoice?->fx_rate === null ? null : (float) $this->invoice->fx_rate;
    }

    public function unitPriceRub(): ?float
    {
        $l = $this->unitPriceCcl();
        $rate = $this->rubRate();

        return $l === null || $rate === null ? null : $l * $rate;
    }

    public function totalRub(): ?float
    {
        $n = $this->unitPriceRub();

        return $n === null ? null : $n * (float) $this->quantity; // unrounded, like the sheet: its column total is the sum of exact values
    }

    public function unitPriceRubRounded(): ?float
    {
        $n = $this->unitPriceRub();

        return $n === null ? null : round($n, 2);
    }

    public function totalRubRounded(): ?float
    {
        $p = $this->unitPriceRubRounded();

        return $p === null ? null : round($p * (float) $this->quantity, 2);
    }
}
