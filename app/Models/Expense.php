<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A company expense; a bank-paid one owns the outgoing bank movement that debited the account. */
class Expense extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    public const STATUSES = ['paid' => ['Ödənilib', 'green'], 'unpaid' => ['Ödənilməyib', 'amber']];

    public const METHODS = ['cash' => 'Nağd', 'bank' => 'Hesabdan köçürmə'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['expense_date' => 'date', 'due_date' => 'date', 'paid_at' => 'date', 'amount' => 'decimal:2', 'cbar_rate' => 'decimal:8', 'amount_azn' => 'decimal:2'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id')->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date && $this->due_date->lt(today());
    }

    public function auditLabel(): string
    {
        return 'Xərc: '.$this->description;
    }
}
