<?php

namespace App\Support;

use App\Models\Deal;

/**
 * The money and goods a deal owes in each direction, per currency (never converted — each
 * party is paid in its own currency):
 *   buyer      — owes us the proforma total minus what it paid; we owe it goods worth what it paid;
 *   seller     — we owe it its invoices' total minus what we paid; it owes us goods worth what we paid;
 *   logistics  — once the buyer's proforma exists we owe the logistics cost, in the currency it was entered.
 * Goods obligations stay open until delivery is recorded (logistics act, next stage).
 */
class DealObligations
{
    /**
     * @return array{buyer: array, seller: array, logistics: array, payable: array<string, float>, receivable: array<string, float>}
     */
    public static function for(Deal $deal): array
    {
        $deal->loadMissing(['invoices', 'salesDocuments', 'payments', 'supplierPayments']);
        $sum = fn ($items, $amount = 'amount') => $items->groupBy('currency')->map(fn ($g) => round($g->sum($amount), 2))->all();

        // Buyer: proforma totals vs what came in.
        $billed = $deal->salesDocuments->where('kind', 'proforma')->groupBy('currency')->map(fn ($g) => round($g->sum(fn ($d) => $d->grandTotal()), 2))->all();
        $received = $sum($deal->payments);
        $buyerDue = self::minus($billed, $received);

        // Seller: its invoices vs what we paid it.
        $supplierInvoices = $deal->invoices->where('type', 'supplier')->where('status', '!=', 'cancelled');
        $invoiced = $sum($supplierInvoices, 'total');
        $paidSeller = $sum($deal->supplierPayments); // in the payment currency, whichever account paid
        $sellerDue = self::minus($invoiced, $paidSeller);

        // Logistics: from the moment the buyer's proforma is ready, in the entered currency.
        $proformaFor = $deal->salesDocuments->where('kind', 'proforma')->pluck('source_invoice_id')->all();
        $logistics = [];
        foreach ($supplierInvoices->filter(fn ($i) => $i->hasLogistics() && in_array($i->id, $proformaFor, true)) as $inv) {
            $logistics[] = ['invoice' => $inv->number, 'amount' => (float) $inv->logistics_amount, 'currency' => $inv->logistics_currency, 'mode' => $inv->logistics_mode];
        }
        $logisticsDue = [];
        foreach ($logistics as $l) {
            $logisticsDue[$l['currency']] = round(($logisticsDue[$l['currency']] ?? 0) + $l['amount'], 2);
        }

        return [
            'buyer' => ['name' => $deal->counterparty?->name, 'billed' => $billed, 'received' => $received, 'due' => $buyerDue, 'goods' => $received],
            'seller' => ['name' => $deal->supplier?->name, 'invoiced' => $invoiced, 'paid' => $paidSeller, 'due' => $sellerDue, 'goods' => $paidSeller],
            'logistics' => ['items' => $logistics, 'due' => $logisticsDue, 'forecast' => collect($logistics)->contains('mode', 'forecast')],
            'payable' => self::plus($sellerDue, $logisticsDue),
            'receivable' => $buyerDue,
        ];
    }

    /** a - b per currency, only positive remainders. */
    private static function minus(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $cur => $v) {
            $left = round($v - ($b[$cur] ?? 0), 2);
            if ($left > 0) {
                $out[$cur] = $left;
            }
        }

        return $out;
    }

    private static function plus(array $a, array $b): array
    {
        foreach ($b as $cur => $v) {
            $a[$cur] = round(($a[$cur] ?? 0) + $v, 2);
        }

        return array_filter($a, fn ($v) => $v > 0);
    }
}
