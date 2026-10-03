<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankTransaction extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2', 'amount_azn' => 'decimal:2', 'cbar_amount_azn' => 'decimal:2',
            'cbar_rate' => 'decimal:8', 'applied_rate' => 'decimal:8',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id')->withTrashed();
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The other leg of a transfer / conversion. */
    public function counterpart(): ?self
    {
        return $this->transfer_group
            ? static::where('transfer_group', $this->transfer_group)->whereKeyNot($this->id)->first()
            : null;
    }

    /** Exchange difference in AZN: positive = better than CBAR for the company. */
    public function exchangeDifference(): float
    {
        $diff = (float) $this->amount_azn - (float) $this->cbar_amount_azn;

        return round($this->direction === 'in' ? $diff : -$diff, 2);
    }

    public function auditLabel(): string
    {
        return ($this->direction === 'in' ? '+' : '-').$this->amount.' '.$this->currency.' '.$this->transaction_date?->format('d.m.Y');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }
}
