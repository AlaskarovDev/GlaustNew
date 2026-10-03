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

    public function unitPriceLog(): ?float
    {
        return $this->logistics === null || (float) $this->quantity <= 0 ? null : ((float) $this->total + (float) $this->logistics) / (float) $this->quantity;
    }

    public function totalCcl(): ?float
    {
        return $this->logistics === null || $this->commission === null ? null : round((float) $this->total + (float) $this->logistics + (float) $this->commission, 2);
    }

    public function unitPriceCcl(): ?float
    {
        $m = $this->totalCcl();

        return $m === null || (float) $this->quantity <= 0 ? null : $m / (float) $this->quantity;
    }
}
