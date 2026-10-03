<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document for the buyer, generated from a seller's invoice and then freely editable:
 *   proforma      — Proforma Invoice (EN): unit price = the sheet's rounded UNIT PRICE RUR (P);
 *   specification — Спецификация к Контракту (RU): starts as the same lines, often adjusted by hand
 *                   (units, quantities, prices) while keeping the proforma total.
 */
class SalesDocument extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    public const KINDS = [
        'proforma' => ['Proforma Invoice', 'Alıcıya proforma faktura (EN)'],
        'specification' => ['Спецификация', 'Müqaviləyə spesifikasiya (RU)'],
    ];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'lines' => 'array', 'freight' => 'decimal:2', 'insurance' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class)->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id')->withTrashed();
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isProforma(): bool
    {
        return $this->kind === 'proforma';
    }

    public function title(): string
    {
        return self::KINDS[$this->kind][0];
    }

    public function label(): string
    {
        return self::KINDS[$this->kind][1];
    }

    /** Sum of the lines (each line total is quantity × unit price, to the kopeck). */
    public function linesTotal(): float
    {
        return round(array_sum(array_map(fn ($l) => (float) ($l['total'] ?? 0), $this->lines ?? [])), 2);
    }

    /** Lines + freight + insurance (the proforma's TOTAL). */
    public function grandTotal(): float
    {
        return round($this->linesTotal() + (float) $this->freight + (float) $this->insurance, 2);
    }

    public function auditLabel(): string
    {
        return $this->title().' '.$this->number;
    }
}
