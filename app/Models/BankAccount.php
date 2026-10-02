<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'opening_date' => 'date', 'is_active' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /** Adds `balance` (account currency) computed in SQL, so lists need no N+1. */
    public function scopeWithBalance(Builder $q): Builder
    {
        return $q->addSelect([
            'balance' => BankTransaction::query()
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)")
                ->whereColumn('bank_transactions.bank_account_id', 'bank_accounts.id'),
        ]);
    }

    public function balance(): float
    {
        $moves = (float) $this->transactions()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as s")
            ->value('s');

        return round((float) $this->opening_balance + $moves, 2);
    }

    public function currentBalance(): float
    {
        return isset($this->attributes['balance'])
            ? round((float) $this->opening_balance + (float) $this->attributes['balance'], 2)
            : $this->balance();
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->currency.')';
    }
}
