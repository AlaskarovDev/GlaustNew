<?php

namespace App\Support\Invoices;

use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A seller invoice entered as two amounts, without its lines ("fakturasız"):
 *   seller — the seller's invoice total, what we owe it (payments, obligations, profit use it);
 *   base   — our amount with commission and other amounts included.
 * It is kept as one line whose commission is (base − seller), so the logistics step, the RUB
 * conversion and the RUR columns work exactly as for an imported invoice. No documents are made.
 */
class ManualInvoice
{
    public const LINE = 'Fakturasız məbləğ';

    public function __construct(private CurrencyRates $rates) {}

    /** @param array{number?: ?string, invoice_date: string, currency: string, seller: float, base: float} $data */
    public function create(Deal $deal, array $data, int $userId): Invoice
    {
        $rate = $this->rate($data['currency'], $data['invoice_date']);

        return DB::transaction(function () use ($deal, $data, $userId, $rate) {
            $invoice = $deal->invoices()->create([
                'project_id' => $deal->project_id, 'type' => 'supplier', 'entry_mode' => 'manual',
                'number' => $data['number'] ?: $this->nextNumber($deal),
                'invoice_date' => $data['invoice_date'], 'counterparty_id' => $deal->supplier_id, 'contract_id' => $deal->purchase_contract_id,
                'currency' => $data['currency'], 'total' => round($data['seller'], 2), 'cbar_rate' => $rate,
                'total_azn' => round($data['seller'] * $rate, 2), 'status' => 'draft', 'created_by' => $userId,
            ]);
            $invoice->items()->create(['line_no' => 1, 'description' => self::LINE, 'quantity' => 1, 'uom' => '', 'unit_price' => round($data['seller'], 2), 'total' => round($data['seller'], 2)]);
            $this->setBase($invoice, $data['seller'], $data['base']);
            if (in_array($deal->status, ['draft', 'active'], true)) {
                $deal->update(['status' => 'invoiced']);
            }

            return $invoice;
        });
    }

    /** @param array{number?: ?string, invoice_date: string, seller: float, base: float} $data */
    public function update(Invoice $invoice, array $data): void
    {
        $rate = $this->rate($invoice->currency, $data['invoice_date']);
        DB::transaction(function () use ($invoice, $data, $rate) {
            $invoice->update(['number' => $data['number'] ?: $invoice->number, 'invoice_date' => $data['invoice_date'],
                'total' => round($data['seller'], 2), 'cbar_rate' => $rate, 'total_azn' => round($data['seller'] * $rate, 2)]);
            $invoice->items()->update(['unit_price' => round($data['seller'], 2), 'total' => round($data['seller'], 2)]);
            $this->setBase($invoice, $data['seller'], $data['base']);
            if ($invoice->hasLogistics() && $invoice->logistics_currency !== $invoice->currency) {
                // logistics in another currency is converted at the invoice date: re-run it for the new date
                app(LogisticsAllocator::class)->apply($invoice->fresh(), $invoice->logistics_mode, 'total', $invoice->logistics_currency, (float) $invoice->logistics_amount);
            }
        });
    }

    private function setBase(Invoice $invoice, float $seller, float $base): void
    {
        $diff = round($base - $seller, 2);
        $invoice->items()->update(['commission' => $diff]);
        $invoice->update(['commission_rate' => $seller > 0 ? round($diff / $seller * 100, 4) : 0, 'commission_total' => $diff, 'commission_updated_at' => now()]);
    }

    private function rate(string $currency, string $date): float
    {
        try {
            return $this->rates->rate($currency, $date);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['invoice_date' => $e->getMessage().__(' Faktura yadda saxlanmadı.')]);
        }
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
