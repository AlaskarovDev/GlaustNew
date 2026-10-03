<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'budget' => 'decimal:2'];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    /** Buyer side ("məhsulu alan tərəf"): counterparty_id + its sale contract. */
    public function saleContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'sale_contract_id')->withTrashed();
    }

    /** Supplier side ("məhsulu göndərən tərəf"). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'supplier_id')->withTrashed();
    }

    public function purchaseContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'purchase_contract_id')->withTrashed();
    }

    /**
     * Gross margin from the two contracts, in AZN at each contract's CBAR rate:
     * ['sale' => ?float, 'purchase' => ?float, 'margin' => ?float, 'percent' => ?float].
     */
    public function contractMargin(): array
    {
        $sale = $this->saleContract?->amount_azn !== null ? (float) $this->saleContract->amount_azn : null;
        $purchase = $this->purchaseContract?->amount_azn !== null ? (float) $this->purchaseContract->amount_azn : null;
        $margin = $sale !== null && $purchase !== null ? round($sale - $purchase, 2) : null;

        return [
            'sale' => $sale,
            'purchase' => $purchase,
            'margin' => $margin,
            'percent' => $margin !== null && $sale > 0 ? round($margin / $sale * 100, 1) : null,
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('due_date');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function progress(): int
    {
        $total = $this->tasks_count ?? $this->tasks()->count();
        if (! $total) {
            return 0;
        }
        $done = $this->done_tasks_count ?? $this->tasks()->where('status', 'done')->count();

        return (int) round($done / $total * 100);
    }

    public function isOverdue(): bool
    {
        return $this->end_date && $this->end_date->isPast() && ! in_array($this->status, ['completed', 'cancelled'], true);
    }

    public function auditLabel(): string
    {
        return $this->code.' '.$this->name;
    }
}
