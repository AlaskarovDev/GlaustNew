<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;

/**
 * Generates the buyer's documents from a seller's invoice whose calculation is complete
 * (logistics + commission + RUB rate), the way the company's workbook does:
 *   PROFORMA: Item #, Description, Custom Code, QTY, UOM, Unit Price RUB (= P), Total (= P × E);
 *   SP:       the same lines in Russian units, then adjusted by hand when needed.
 *
 * Free-text blocks (seller, customer, terms, signatories) are carried over from the company's
 * previous document of the same kind — and for the customer, of the same buyer — so they are
 * typed once; the first time they come from the company and counterparty records.
 */
class SalesDocumentBuilder
{
    /** Units as printed on a Russian specification. */
    public const RU_UNITS = [
        'kg' => 'кг', 'kq' => 'кг', 'g' => 'г', 't' => 'т', 'ton' => 'т', 'tn' => 'т',
        'qm' => 'м2', 'm2' => 'м2', 'sqm' => 'м2', 'm²' => 'м2', 'm' => 'м', 'm3' => 'м3', 'cbm' => 'м3',
        'l' => 'л', 'lt' => 'л', 'pcs' => 'шт', 'pc' => 'шт', 'ədəd' => 'шт', 'set' => 'компл', 'roll' => 'рул',
    ];

    /**
     * Create the proforma and the specification of $invoice if its calculation is complete and
     * they do not exist yet. Existing documents are never overwritten (they may hold edits).
     *
     * @return list<SalesDocument> the documents created now
     */
    public function ensureFor(Invoice $invoice): array
    {
        if ($invoice->type !== 'supplier' || ! $invoice->rubReady()) {
            return [];
        }
        $created = [];
        DB::transaction(function () use ($invoice, &$created) {
            $existing = SalesDocument::where('source_invoice_id', $invoice->id)->pluck('kind')->all();
            foreach (array_keys(SalesDocument::KINDS) as $kind) {
                if (! in_array($kind, $existing, true)) {
                    $created[] = $this->create($invoice, $kind);
                }
            }
        });

        return $created;
    }

    /** Lines computed from the invoice now, in the shape stored on a document. */
    public function computedLines(Invoice $invoice, string $kind): array
    {
        $invoice->loadMissing('items');
        $invoice->items->each->setRelation('invoice', $invoice);

        return $invoice->items->map(function ($it) use ($kind) {
            $price = $it->unitPriceRubRounded();
            $qty = (float) $it->quantity;

            return [
                'n' => (int) $it->line_no,
                'description' => (string) $it->description,
                'hs_code' => (string) $it->hs_code,
                'uom' => $kind === 'specification' ? $this->ruUnit((string) $it->uom) : (string) $it->uom,
                'quantity' => $qty,
                'unit_price' => $price,
                'total' => $price === null ? 0.0 : round($qty * $price, 2),
            ];
        })->values()->all();
    }

    /** Replace a document's lines with the current calculation (an explicit user action). */
    public function refreshLines(SalesDocument $doc): void
    {
        $invoice = $doc->sourceInvoice;
        if (! $invoice || ! $invoice->rubReady()) {
            throw new \RuntimeException('Mənbə fakturanın hesablaması tam deyil.');
        }
        $lines = $this->computedLines($invoice, $doc->kind);
        $doc->update(['lines' => $lines, 'total' => $this->total($lines, $doc->freight, $doc->insurance), 'updated_by' => auth()->id()]);
    }

    /** True when the stored lines no longer match what the calculation gives now. */
    public function isStale(SalesDocument $doc): bool
    {
        $invoice = $doc->sourceInvoice;
        if (! $invoice || ! $invoice->rubReady()) {
            return false;
        }
        $key = fn (array $lines) => array_map(fn ($l) => [(int) $l['n'], round((float) $l['quantity'], 3), round((float) $l['unit_price'], 2)], $lines);

        return $key($this->computedLines($invoice, $doc->kind)) !== $key($doc->lines ?? []);
    }

    public function ruUnit(string $uom): string
    {
        return self::RU_UNITS[mb_strtolower(trim($uom))] ?? $uom;
    }

    public function total(array $lines, $freight = null, $insurance = null): float
    {
        return round(array_sum(array_map(fn ($l) => (float) ($l['total'] ?? 0), $lines)) + (float) $freight + (float) $insurance, 2);
    }

    private function create(Invoice $invoice, string $kind): SalesDocument
    {
        $invoice->loadMissing('deal.saleContract', 'deal.counterparty.contacts');
        $deal = $invoice->deal;
        $buyer = $deal->counterparty;
        $contract = $deal->saleContract;
        $company = tenant();
        $date = $invoice->fx_date ?? today();

        $previous = SalesDocument::where('kind', $kind)->latest('id')->first();
        $previousForBuyer = $buyer ? SalesDocument::where('kind', $kind)->where('counterparty_id', $buyer->id)->latest('id')->first() : null;
        $proforma = $kind === 'specification' ? SalesDocument::where('source_invoice_id', $invoice->id)->where('kind', 'proforma')->first() : null;

        $lines = $this->computedLines($invoice, $kind);
        $ru = $kind === 'specification';

        return SalesDocument::create([
            'deal_id' => $deal->id,
            'project_id' => $deal->project_id,
            'source_invoice_id' => $invoice->id,
            'kind' => $kind,
            'number' => $proforma?->number ?? $this->nextNumber($date),
            'doc_date' => $date,
            'contract_number' => $contract?->number,
            'contract_date' => $contract?->contract_date?->format('d.m.Y'),
            'counterparty_id' => $buyer?->id,
            'currency' => 'RUB',
            'heading' => $previous?->heading ?? mb_strtoupper($company->name),
            'seller_block' => $previous?->seller_block ?? $this->companyBlock($company),
            'customer_block' => $previousForBuyer?->customer_block ?? ($buyer ? $this->counterpartyBlock($buyer) : null),
            'payment_terms' => $previous?->payment_terms ?? ($ru ? '100% предоплата' : '100% advance payment'),
            'delivery_terms' => $previous?->delivery_terms,
            'seller_signatory' => $previous?->seller_signatory ?? ($ru ? 'Генеральный директор ' : null),
            'buyer_signatory' => $previousForBuyer?->buyer_signatory ?? ($ru ? 'Генеральный директор ' : null),
            'lines' => $lines,
            'total' => $this->total($lines),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);
    }

    /** "P{n}/{ddmm}" like the company's P23/0804: n = proformas so far + 1. */
    private function nextNumber(\DateTimeInterface $date): string
    {
        $n = SalesDocument::withTrashed()->where('kind', 'proforma')->count() + 1;

        return 'P'.$n.'/'.$date->format('dm');
    }

    private function companyBlock($company): string
    {
        return implode("\n", array_filter([$company->name, $company->address, $company->phone, $company->email]));
    }

    private function counterpartyBlock($c): string
    {
        $contactEmail = $c->contacts?->first(fn ($p) => $p->email)?->email;

        return implode("\n", array_filter([
            $c->name,
            $c->address,
            implode(', ', array_filter([$c->city, $c->country])),
            $c->phone,
            $c->email,
            $contactEmail && $contactEmail !== $c->email ? $contactEmail : null,
        ]));
    }
}
