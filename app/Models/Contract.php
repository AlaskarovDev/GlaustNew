<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'contract_date' => 'date', 'start_date' => 'date', 'end_date' => 'date', 'rate_date' => 'date',
            'amount' => 'decimal:2', 'amount_azn' => 'decimal:2', 'cbar_rate' => 'decimal:8', 'auto_renew' => 'boolean',
        ];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'parent_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Contract::class, 'parent_id')->orderBy('contract_date');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ContractPayment::class)->orderBy('due_date');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /** Money actually moved under this contract, in AZN (in for sales, out for purchases). */
    public function settledAzn(): float
    {
        $direction = $this->kind === 'sale' ? 'in' : 'out';

        return (float) $this->transactions()->where('direction', $direction)->sum('amount_azn');
    }

    public function daysLeft(): ?int
    {
        return $this->end_date ? (int) now()->startOfDay()->diffInDays($this->end_date, false) : null;
    }

    public function auditLabel(): string
    {
        return $this->number;
    }
}
