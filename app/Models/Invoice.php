<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * supplier — the seller's proforma to us (imported from Excel), bound to the deal's purchase contract;
 * customer — ours to the buyer (next stage), bound to the sale contract.
 */
class Invoice extends Model
{
    use Auditable, BelongsToCompany, HasAttachments, SoftDeletes;

    public const TYPES = ['supplier' => 'Satıcının fakturası', 'customer' => 'Alıcıya faktura'];

    public const STATUSES = ['draft' => ['Qaralama', 'slate'], 'confirmed' => ['Təsdiqlənib', 'blue'], 'paid' => ['Ödənilib', 'green'], 'cancelled' => ['Ləğv', 'rose']];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'total' => 'decimal:2', 'total_azn' => 'decimal:2', 'cbar_rate' => 'decimal:8',
            'logistics_amount' => 'decimal:2', 'logistics_rate' => 'decimal:8', 'logistics_total' => 'decimal:2', 'logistics_updated_at' => 'datetime',
            'commission_rate' => 'decimal:4', 'commission_total' => 'decimal:2', 'commission_updated_at' => 'datetime',
            'fx_date' => 'date', 'fx_bulletin_date' => 'date', 'fx_base_azn' => 'decimal:8', 'fx_target_azn' => 'decimal:8', 'fx_rate' => 'decimal:12', 'fx_updated_at' => 'datetime',
            'approval_flow' => 'array', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('line_no')->orderBy('id');
    }

    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'source_invoice_id')->orderBy('kind');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public const LOGISTICS_MODES = ['forecast' => 'Proqnoz', 'actual' => 'Dəqiq'];

    public const LOGISTICS_METHODS = ['total' => 'Ümumi məbləğ (Total/EUR payına görə bölünür)', 'per_item' => 'Hər məhsul üzrə ayrıca'];

    public function hasLogistics(): bool
    {
        return $this->logistics_method !== null;
    }

    public function hasCommission(): bool
    {
        return $this->commission_rate !== null;
    }

    /** "3.5" from 3.5000 — for labels such as "Commission 3.5%" */
    public function commissionLabel(): string
    {
        return $this->hasCommission() ? rtrim(rtrim(number_format((float) $this->commission_rate, 4, ',', ''), '0'), ',') : '';
    }

    public function hasRub(): bool
    {
        return $this->fx_rate !== null;
    }

    /** Entered as amounts only, without the seller's lines: no documents are prepared for it. */
    public function isManual(): bool
    {
        return $this->entry_mode === 'manual';
    }

    /**
     * What we bill the buyer for a manual entry, in the Trade's sale currency: the rounded RUR total
     * of its line (as a proforma would be built). Null until the calculation is complete.
     */
    public function saleTotal(): ?float
    {
        if (! $this->isManual() || ! $this->rubReady()) {
            return null;
        }
        $this->loadMissing('items');
        $this->items->each->setRelation('invoice', $this);

        return round((float) $this->items->sum(fn ($it) => (float) $it->totalRubRounded()), 2);
    }

    /** Every input of the RUR columns is in: logistics, commission and the RUB rate. */
    public function rubReady(): bool
    {
        return $this->hasLogistics() && $this->hasCommission() && $this->hasRub();
    }

    public const APPROVAL_STATUSES = ['pending' => ['Təsdiqdədir', 'amber'], 'approved' => ['Təsdiqlənib', 'green'], 'rejected' => ['Geri qaytarılıb', 'rose'], 'unlocked' => ['Müvəqqəti kilidsiz', 'amber']];

    public function approvals(): HasMany
    {
        return $this->hasMany(InvoiceApproval::class)->orderBy('id');
    }

    /** No edits while an approval is running or after it was approved. */
    public function isLocked(): bool
    {
        return in_array($this->approval_status, ['pending', 'approved'], true);
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /** User id of whoever has to decide now (null unless pending). */
    public function currentApproverId(): ?int
    {
        return $this->approval_status === 'pending' ? ($this->approval_flow[$this->approval_step]['user_id'] ?? null) : null;
    }

    /** Corrections of this (seller) invoice, oldest first. */
    public function adjustments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InvoiceAdjustment::class)->orderBy('adjustment_date')->orderBy('id');
    }

    /** The invoice amount after its corrections (what we really owe the seller). */
    public function adjustedTotal(): float
    {
        return round((float) $this->total + (float) $this->adjustments->sum('amount'), 2);
    }

    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type] ?? $this->type);
    }

    public function auditLabel(): string
    {
        return $this->typeLabel().' '.$this->number;
    }
}
