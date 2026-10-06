<?php

namespace App\Support\Invoices;

use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A seller invoice entered as its Total only, without the seller's lines ("fakturasız"). It is kept as
 * one line, so logistics, commission and the RUB conversion are applied exactly as for an imported
 * invoice. No documents are prepared for it.
 */
class ManualInvoice
{
    public const LINE = 'Fakturasız məbləğ';

    public function __construct(private CurrencyRates $rates) {}

    /** @param array{number?: ?string, invoice_date: string, currency: string, amount: float} $data */
    public function create(Deal $deal, array $data, int $userId): Invoice
    {
        try {
            $rate = $this->rates->rate($data['currency'], $data['invoice_date']);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['invoice_date' => $e->getMessage().__(' Faktura yadda saxlanmadı.')]);
        }
        $amount = round($data['amount'], 2);

        return DB::transaction(function () use ($deal, $data, $userId, $rate, $amount) {
            $invoice = $deal->invoices()->create([
                'project_id' => $deal->project_id, 'type' => 'supplier', 'entry_mode' => 'manual',
                'number' => $data['number'] ?: $this->nextNumber($deal),
                'invoice_date' => $data['invoice_date'], 'counterparty_id' => $deal->supplier_id, 'contract_id' => $deal->purchase_contract_id,
                'currency' => $data['currency'], 'total' => $amount, 'cbar_rate' => $rate,
                'total_azn' => round($amount * $rate, 2), 'status' => 'draft', 'created_by' => $userId,
            ]);
            $invoice->items()->create(['line_no' => 1, 'description' => self::LINE, 'quantity' => 1, 'uom' => '', 'unit_price' => $amount, 'total' => $amount]);
            if (in_array($deal->status, ['draft', 'active'], true)) {
                $deal->update(['status' => 'invoiced']);
            }

            return $invoice;
        });
    }

    private function nextNumber(Deal $deal): string
    {
        $n = $deal->invoices()->withTrashed()->where('type', 'supplier')->where('entry_mode', 'manual')->count() + 1;
        do {
            $number = 'M-'.$deal->code.'-'.$n++;
        } while ($deal->invoices()->withTrashed()->where('type', 'supplier')->where('number', $number)->exists());

        return $number;
    }
}
