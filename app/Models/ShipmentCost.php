<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentCost extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['cost_date' => 'date', 'amount' => 'decimal:2', 'amount_azn' => 'decimal:2', 'cbar_rate' => 'decimal:8'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function auditLabel(): string
    {
        return config('glaust.cost_types.'.$this->cost_type).' '.$this->amount.' '.$this->currency;
    }
}
