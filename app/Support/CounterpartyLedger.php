<?php

namespace App\Support;

use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRevision;
use App\Models\SupplierPayment;
use Illuminate\Support\Collection;

/**
 * Debit / credit statement of a counterparty, per currency (debit = it owes us more, credit = we owe it more):
 *   buyer      — the commercial invoice puts it in debt (its corrections raise / lower the debt), its payments clear it;
 *   seller     — once our commercial invoice is issued we owe the seller its invoice; our payments clear it;
 *   logistics  — its invoices are our debt; our payments clear it;
 *   other bank movements with the party (refunds, advances…) count by direction.
 * Balance > 0: it owes us; < 0: we owe it.
 */
class CounterpartyLedger
{
    /**
     * @return array{entries: list<array>, balances: array<string, float>, opening: array<string, float>}
     *         entries oldest first (within from–to when given), each with the running balance of its currency;
     *         opening = balances before `from`; balances = at the end of the period
     */
    public static function for(Counterparty $cp, ?string $from = null, ?string $to = null): array
    {
        $e = [];
        $push = function ($date, string $doc, string $text, string $cur, float $debit, float $credit, ?string $url = null) use (&$e) {
            if (abs($debit) < 0.005 && abs($credit) < 0.005) {
                return;
            }
            $e[] = ['date' => $date, 'doc' => $doc, 'text' => $text, 'cur' => $cur, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'url' => $url];
        };

        // buyer: commercial invoices, as first issued, then every correction of their total
        $commercials = SalesDocument::with(['deal', 'revisions'])->where('kind', 'commercial')->where('counterparty_id', $cp->id)->get();
        foreach ($commercials as $ci) {
            $first = $ci->revisions->sortBy('id')->first();
            $issued = $first ? (float) $first->total_before : $ci->grandTotal();
            $push($ci->doc_date, 'Commercial Invoice '.$ci->number, 'Trade '.$ci->deal?->code, $ci->currency, $issued, 0, route('sales-documents.show', $ci));
            foreach ($ci->revisions->sortBy('id') as $rv) {
                $d = (float) $rv->difference;
                $push($rv->created_at, __('Düzəliş').' · '.$ci->number, $rv->reason ?: __('Commercial Invoice dəyişdi'), $ci->currency, max(0, $d), max(0, -$d), route('sales-documents.show', $ci));
            }
        }

        // buyer: amounts entered without an invoice bill their RUR total once calculated (no documents)
        $manualSales = Invoice::with(['deal', 'items'])->where('type', 'supplier')->where('entry_mode', 'manual')->where('status', '!=', 'cancelled')
            ->whereHas('deal', fn ($q) => $q->where('counterparty_id', $cp->id))->get();
        foreach ($manualSales as $inv) {
            if (($sale = $inv->saleTotal()) !== null) {
                $push($inv->fx_date ?? $inv->invoice_date, __('Fakturasız hesablama').' '.$inv->number, 'Trade '.$inv->deal?->code, \App\Support\Invoices\RubConverter::target($inv), $sale, 0, route('invoices.show', $inv));
            }
        }

        // seller: its invoices become our debt with our commercial invoice (a manual entry: at once)
        $withCommercial = SalesDocument::where('kind', 'commercial')->pluck('source_invoice_id')->filter()->all();
        $sellerInvoices = Invoice::with('deal')->where('type', 'supplier')->where('status', '!=', 'cancelled')->where('counterparty_id', $cp->id)
            ->where(fn ($q) => $q->whereIn('id', $withCommercial)->orWhere('entry_mode', 'manual'))->get();
        $ciDates = SalesDocument::where('kind', 'commercial')->pluck('doc_date', 'source_invoice_id');
        foreach ($sellerInvoices as $inv) {
            $push($ciDates[$inv->id] ?? $inv->approved_at ?? $inv->invoice_date, __('Satıcı fakturası').' '.$inv->number, 'Trade '.$inv->deal?->code, $inv->currency, 0, (float) $inv->total, route('invoices.show', $inv));
            foreach ($inv->adjustments()->get() as $adj) {   // − : the seller owes us back
                $push($adj->adjustment_date, __('Düzəliş').' · '.$inv->number, $adj->reason ?? '', $adj->currency, max(0, -(float) $adj->amount), max(0, (float) $adj->amount));
            }
        }
        $supplierPayments = SupplierPayment::with('deal')->where('counterparty_id', $cp->id)->get();
        foreach ($supplierPayments as $p) {
            $push($p->payment_date, __('Satıcıya ödəniş'), 'Trade '.$p->deal?->code.($p->reference ? ' · '.$p->reference : ''), $p->currency, (float) $p->amount, 0);
        }

        // logistics company: its invoices are our debt, payments clear them (in the invoice's currency)
        $acts = LogisticsAct::with('deal')->where('counterparty_id', $cp->id)->get();
        foreach ($acts as $a) {
            $push($a->docDate(), $a->label(), 'Trade '.$a->deal?->code, $a->currency, 0, (float) $a->amount, route('deals.show', [$a->deal_id, 'tab' => 'logistics']));
        }
        $logisticsPayments = LogisticsPayment::with('act')->whereIn('logistics_act_id', $acts->pluck('id'))->get();
        foreach ($logisticsPayments as $lp) {
            $push($lp->payment_date, __('Logistika ödənişi'), $lp->act?->label().' · '.money($lp->amount, $lp->currency), $lp->act?->currency ?? $lp->currency, (float) $lp->act_amount, 0);
        }

        // any other bank movement with the party: money in clears what it owes, money out what we owe
        $skip = $supplierPayments->pluck('transaction_id')->merge($logisticsPayments->pluck('transaction_id'))->filter()->all();
        foreach (BankTransaction::with('deal')->where('counterparty_id', $cp->id)->where('kind', 'regular')->whereNotIn('id', $skip)->get() as $t) {
            $in = $t->direction === 'in';
            $push($t->transaction_date, $in ? __('Daxilolma') : __('Ödəniş'), trim(($t->deal ? 'Trade '.$t->deal->code.' · ' : '').($t->purpose ?? '')), $t->currency,
                $in ? 0 : (float) $t->amount, $in ? (float) $t->amount : 0, auth()->user()?->can('bank.view') ? route('bank.transactions.show', $t) : null);
        }

        usort($e, fn ($a, $b) => strcmp(self::key($a['date']), self::key($b['date'])));
        $running = [];
        $opening = [];
        $kept = [];
        foreach ($e as $row) {
            $day = substr(self::key($row['date']), 0, 10);
            if ($to && $day > $to) {
                break;
            }
            $running[$row['cur']] = round(($running[$row['cur']] ?? 0) + $row['debit'] - $row['credit'], 2);
            $row['balance'] = $running[$row['cur']];
            if ($from && $day < $from) {
                $opening[$row['cur']] = $row['balance'];
                continue;
            }
            $kept[] = $row;
        }
        $nonZero = fn ($a) => array_filter($a, fn ($v) => abs($v) >= 0.005);

        return ['entries' => $kept, 'balances' => $nonZero($running), 'opening' => $nonZero($opening)];
    }

    /** Current balances of every counterparty of the company (computed once per request). @return array<int, array<string, float>> */
    public static function all(): array
    {
        $attrs = request()->attributes;   // per request, never across requests
        if (! $attrs->has('counterparty_balances')) {
            $attrs->set('counterparty_balances', Counterparty::query()->get()->mapWithKeys(fn (Counterparty $c) => [$c->id => self::for($c)['balances']])->all());
        }

        return $attrs->get('counterparty_balances');
    }

    /** Totals over all counterparties: what they owe us and what we owe, per currency, with how many parties. */
    public static function totals(): array
    {
        $owes = $owe = [];
        $n = ['owes_us' => 0, 'we_owe' => 0];
        foreach (self::all() as $balances) {
            $pos = $neg = false;
            foreach ($balances as $cur => $v) {
                if ($v > 0) {
                    $owes[$cur] = round(($owes[$cur] ?? 0) + $v, 2);
                    $pos = true;
                } else {
                    $owe[$cur] = round(($owe[$cur] ?? 0) - $v, 2);
                    $neg = true;
                }
            }
            $n['owes_us'] += (int) $pos;
            $n['we_owe'] += (int) $neg;
        }

        return ['owes_us' => $owes, 'we_owe' => $owe, 'count' => $n];
    }

    /** Ids of parties that owe us (`owes_us`) or that we owe (`we_owe`) in any currency. */
    public static function idsWith(string $side): array
    {
        return array_keys(array_filter(self::all(), fn ($b) => (bool) array_filter($b, fn ($v) => $side === 'owes_us' ? $v > 0 : $v < 0)));
    }

    /** Balances of many counterparties at once (for lists). @return array<int, array<string, float>> */
    public static function balances(Collection $counterparties): array
    {
        return $counterparties->mapWithKeys(fn (Counterparty $c) => [$c->id => self::for($c)['balances']])->all();
    }

    private static function key($date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d H:i:s') : (string) $date;
    }
}
