<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Tədarük: one buy-and-resell lot of a project (see the deals migration). */
class Deal extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    public const STATUSES = ['draft', 'active', 'invoiced', 'completed', 'cancelled'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['deal_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function saleContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'sale_contract_id')->withTrashed();
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'supplier_id')->withTrashed();
    }

    public function purchaseContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'purchase_contract_id')->withTrashed();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('invoice_date')->orderBy('id');
    }

    public function supplierInvoices(): HasMany
    {
        return $this->invoices()->where('type', 'supplier');
    }

    public function customerInvoices(): HasMany
    {
        return $this->invoices()->where('type', 'customer');
    }

    public function auditLabel(): string
    {
        return $this->code.' '.$this->title;
    }
}
