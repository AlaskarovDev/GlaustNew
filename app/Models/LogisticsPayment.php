<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One part of paying a logistics act: share of the act, paid currency, bank rate, fee (see the migration). */
class LogisticsPayment extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'act_amount' => 'decimal:2', 'cbar_act_rate' => 'decimal:8', 'cbar_rate' => 'decimal:8',
            'cbar_cross' => 'decimal:12', 'bank_rate' => 'decimal:12', 'amount_cbar' => 'decimal:2', 'amount' => 'decimal:2',
            'difference' => 'decimal:2', 'difference_azn' => 'decimal:2', 'fee_percent' => 'decimal:4', 'fee_minimum' => 'decimal:2',
            'fee_maximum' => 'decimal:2', 'fee_amount' => 'decimal:2', 'fee_azn' => 'decimal:2', 'fee_eur' => 'decimal:2',
            'fee_account_amount' => 'decimal:2', 'fee_bank_rate' => 'decimal:12', 'fee_difference_azn' => 'decimal:2'];
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(LogisticsAct::class, 'logistics_act_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id')->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function feeExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'fee_expense_id');
    }

    /** The fee came off another account than the payment. */
    public function feeFromOtherAccount(): bool
    {
        return $this->fee_account_id !== null && (int) $this->fee_account_id !== (int) $this->bank_account_id;
    }

    /** Taken from the payment account: the payment, plus the fee when it came off the same account. */
    public function totalDebit(): float
    {
        return round((float) $this->amount + ($this->feeFromOtherAccount() ? 0 : (float) $this->fee_amount), 2);
    }

    public function auditLabel(): string
    {
        return 'Logistika ödənişi '.$this->amount.' '.$this->currency;
    }
}
