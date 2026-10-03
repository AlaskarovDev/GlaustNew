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
            'fx_date' => 'date', 'fx_bulletin_date' => 'date', 'fx_base_azn' => 'decimal:8', 'fx_target_azn' => 'decimal:8', 'fx_rate' => 'decimal:12', 'fx_updated_at' => 'datetime'];
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

    /** Every input of the RUR columns is in: logistics, commission and the RUB rate. */
    public function rubReady(): bool
    {
        return $this->hasLogistics() && $this->hasCommission() && $this->hasRub();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function auditLabel(): string
    {
        return $this->typeLabel().' '.$this->number;
    }
}
