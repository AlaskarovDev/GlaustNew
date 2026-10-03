<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The logistics company's act for a deal; paid in one or more parts (see the migration). */
class LogisticsAct extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    public const STATUSES = ['unpaid' => ['Ödənilməyib', 'amber'], 'partial' => ['Qismən ödənilib', 'blue'], 'paid' => ['Ödənilib', 'green']];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['act_date' => 'date', 'logistics_invoice_date' => 'date', 'planned_date' => 'date', 'amount' => 'decimal:2',
            'cbar_rate' => 'decimal:8', 'cbar_rub' => 'decimal:8', 'cbar_eur' => 'decimal:8',
            'amount_azn' => 'decimal:2', 'amount_rub' => 'decimal:2', 'amount_eur' => 'decimal:2'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LogisticsPayment::class)->orderBy('payment_date')->orderBy('id');
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /** Settled part of the act, in the act currency. */
    public function paid(): float
    {
        return round((float) $this->payments->sum('act_amount'), 2);
    }

    public function remaining(): float
    {
        return max(0.0, round((float) $this->amount - $this->paid(), 2));
    }

    public function status(): string
    {
        return match (true) {
            $this->remaining() <= 0 => 'paid',
            $this->paid() > 0 => 'partial',
            default => 'unpaid',
        };
    }

    public function auditLabel(): string
    {
        return 'Logistika aktı '.$this->act_number;
    }
}
