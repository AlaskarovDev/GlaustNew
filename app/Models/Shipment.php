<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    public const FLOW = ['planned', 'loading', 'in_transit', 'customs', 'arrived', 'delivered'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'loading_date' => 'date', 'eta' => 'date', 'delivered_at' => 'datetime',
            'weight_kg' => 'decimal:2', 'volume_m3' => 'decimal:3',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'carrier_id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ShipmentStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ShipmentCost::class)->orderBy('cost_date');
    }

    public function isDelayed(): bool
    {
        return $this->eta && $this->eta->lt(today()) && ! in_array($this->status, ['arrived', 'delivered'], true);
    }

    public function stepIndex(): int
    {
        return (int) array_search($this->status, self::FLOW, true);
    }

    public function auditLabel(): string
    {
        return $this->number;
    }
}
