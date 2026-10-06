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
        'commercial' => ['Commercial Invoice', 'Kommersiya fakturası (təsdiqdən sonra)'],
        'packing' => ['Packing List', 'Qablaşdırma siyahısı — paletlər və çəkilər (EN)'],
    ];

    /** Created automatically when the calculation is complete; the commercial invoice comes from the approval. */
    public const AUTO_KINDS = ['proforma', 'specification'];

    protected $guarded = ['id', 'company_id'];

    /** Why the next save changes the total (kept on the revision); set by the code that saves. */
    public ?string $revisionReason = null;

    protected static function booted(): void
    {
        // every change of the total is kept with its difference
        static::updated(function (SalesDocument $doc) {
            if (! $doc->wasChanged('total')) {
                return;
            }
            $before = (float) $doc->getOriginal('total');
            $after = (float) $doc->total;
            SalesDocumentRevision::create([
                'sales_document_id' => $doc->id, 'deal_id' => $doc->deal_id, 'kind' => $doc->kind, 'currency' => $doc->currency,
                'total_before' => $before, 'total_after' => $after, 'difference' => round($after - $before, 2),
                'reason' => $doc->revisionReason, 'user_id' => auth()->id(),
            ]);
            $doc->revisionReason = null;
        });
    }

    public function revisions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesDocumentRevision::class)->orderByDesc('id');
    }

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

    /** The commercial invoice is final; the others freeze while their invoice is in approval or approved. */
    public function isLocked(): bool
    {
        if ($this->kind === 'packing') {
            return false; // pallets and weights are often known only after the invoice is locked
        }

        // the commercial invoice is final — except while its invoice is temporarily unlocked for corrections
        if ($this->kind === 'commercial') {
            return $this->sourceInvoice?->approval_status !== 'unlocked';
        }

        return (bool) $this->sourceInvoice?->isLocked();
    }

    public function title(): string
    {
        return self::KINDS[$this->kind][0];
    }

    public function label(): string
    {
        return __(self::KINDS[$this->kind][1]);
    }

    /** One line for lists: the amount, or pallets and weight for a packing list. */
    public function summary(): string
    {
        if ($this->isPacking()) {
            $t = $this->packingTotals();

            return $t['pallets'].' '.__('palet').' · '.num($t['weight']).' kg';
        }

        return money($this->grandTotal(), $this->currency);
    }

    public function isPacking(): bool
    {
        return $this->kind === 'packing';
    }

    /** Packing list: [{title, packing, weight, items: [{code, description, package, quantity, qty_unit, total, total_unit, weight}]}]. */
    public function pallets(): array
    {
        return $this->isPacking() ? array_values((array) $this->lines) : [];
    }

    /** @return array{pallets: int, weight: float, packed: float} pallets, weight with pallets, weight with packing */
    public function packingTotals(): array
    {
        $p = $this->pallets();

        return [
            'pallets' => count($p),
            'weight' => round(array_sum(array_map(fn ($x) => (float) ($x['weight'] ?? 0), $p)), 2),
            'packed' => round(array_sum(array_map(fn ($x) => array_sum(array_map(fn ($i) => (float) ($i['weight'] ?? 0), $x['items'] ?? [])), $p)), 2),
        ];
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
