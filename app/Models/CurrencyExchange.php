<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A currency purchase or sale with the CBAR and bank rates kept side by side (see the migration). */
class CurrencyExchange extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    public const DIRECTIONS = ['buy' => 'Alış', 'sell' => 'Satış'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['exchange_date' => 'date', 'amount' => 'decimal:2', 'cbar_rate' => 'decimal:8', 'cbar_counter_rate' => 'decimal:8',
            'cbar_cross' => 'decimal:12', 'bank_rate' => 'decimal:12', 'counter_amount_cbar' => 'decimal:2', 'counter_amount' => 'decimal:2',
            'difference' => 'decimal:2', 'difference_azn' => 'decimal:2'];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_account_id')->withTrashed();
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_account_id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return self::DIRECTIONS[$this->direction].' '.$this->amount.' '.$this->currency;
    }
}
