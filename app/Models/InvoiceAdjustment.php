<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A correction of a seller invoice in its currency (− the seller owes us back / we owe less, + we owe more). */
class InvoiceAdjustment extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['adjustment_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return 'Satıcı fakturasına düzəliş '.$this->amount.' '.$this->currency;
    }
}
