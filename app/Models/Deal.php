<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Trade: one buy-and-resell lot of a project (see the deals migration). */
class Deal extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    public const STATUSES = ['draft', 'active', 'invoiced', 'completed', 'cancelled'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['deal_date' => 'date'];
    }

    /** What the buyer pays in: the proforma, commercial invoice and the conversion step use it. */
    public function saleCurrency(): string
    {
        return $this->sale_currency ?: 'RUB';
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

    /** Incoming payments from the buyer (bank movements linked to this deal). */
    public function payments(): HasMany
    {
        return $this->hasMany(BankTransaction::class)->where('direction', 'in')->orderByDesc('transaction_date')->orderByDesc('id');
    }

    /** Outgoing bank movements of this deal; those to the seller are our payments for the goods. */
    public function outgoing(): HasMany
    {
        return $this->hasMany(BankTransaction::class)->where('direction', 'out')->orderByDesc('transaction_date')->orderByDesc('id');
    }

    /** Our payments to the seller (amount in the invoice currency, debit + fee on the account). */
    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderByDesc('payment_date')->orderByDesc('id');
    }

    /** Currency exchanges done for this Trade (their CBAR difference belongs to its profit). */
    public function currencyExchanges(): HasMany
    {
        return $this->hasMany(CurrencyExchange::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function logisticsActs(): HasMany
    {
        return $this->hasMany(LogisticsAct::class)->orderBy('logistics_invoice_date')->orderBy('id');
    }

    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class)->orderBy('source_invoice_id')->orderBy('kind');
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
