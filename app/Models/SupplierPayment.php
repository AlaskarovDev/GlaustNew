<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Our payment to a deal's seller, with CBAR vs bank rate and the bank's fee (see the migration). */
class SupplierPayment extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:2', 'cbar_rate' => 'decimal:8', 'cbar_account_rate' => 'decimal:8',
            'cbar_cross' => 'decimal:12', 'bank_rate' => 'decimal:12', 'account_amount_cbar' => 'decimal:2', 'account_amount' => 'decimal:2',
            'difference' => 'decimal:2', 'difference_azn' => 'decimal:2', 'fee_bank_rate' => 'decimal:12', 'fee_difference_azn' => 'decimal:2', 'fee_percent' => 'decimal:4', 'fee_minimum' => 'decimal:2', 'fee_maximum' => 'decimal:2',
            'fee_amount' => 'decimal:2', 'fee_account_amount' => 'decimal:2'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
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

    /** Total taken from the account: the payment plus the fee. */
    /** Debited from the payment account: the payment, plus the fee when it was paid from the same account. */
    public function totalDebit(): float
    {
        return round((float) $this->account_amount + ($this->feeFromOtherAccount() ? 0 : (float) $this->fee_account_amount), 2);
    }

    public function feeFromOtherAccount(): bool
    {
        return $this->fee_account_id && (int) $this->fee_account_id !== (int) $this->bank_account_id;
    }

    public function feeCurrency(): string
    {
        return $this->fee_account_currency ?? $this->account_currency;
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'fee_account_id')->withTrashed();
    }

    public function auditLabel(): string
    {
        return 'Satıcıya ödəniş '.$this->amount.' '.$this->currency;
    }

    /** Fee by the configured rule for a currency: percent, but within [minimum, maximum]; null when there is no rule. */
    public static function feeFor(string $currency, float $amount): ?array
    {
        $rule = config('glaust.bank_fees.'.$currency);
        if (! $rule) {
            return null;
        }

        $min = (float) $rule['minimum'];
        $max = isset($rule['maximum']) ? (float) $rule['maximum'] : INF;

        return ['percent' => (float) $rule['percent'], 'minimum' => $min, 'maximum' => is_finite($max) ? $max : null,
            'amount' => round(min(max($amount * $rule['percent'] / 100, $min), $max), 2)];
    }
}
