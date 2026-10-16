<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankAuditLine;
use App\Models\BankTransaction;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\LogisticsPayment;
use App\Models\SupplierPayment;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «Düzəlt» of the bank audit: makes the system's movement what the bank statement shows.
 *   create — a statement line the system lacks becomes a movement of the account (purpose = the bank's text);
 *   date   — the movement, and what it belongs to (seller / logistics payment, expense, currency exchange), moves to the
 *            bank's day with every CBAR figure of that day recomputed;
 *   amount — a plain movement takes the bank's amount;
 *   delete — a movement the bank never made goes, together with what it belongs to.
 * Rates come only from CBAR; a missing one stops the fix with its message.
 */
class BankAuditFixer
{
    public function __construct(
        private BankLedger $ledger, private CurrencyRates $rates, private SupplierPaymentService $supplier,
        private LogisticsService $logistics, private ExpenseService $expenses, private CurrencyExchangeService $exchanges,
    ) {}

    /** @throws RateUnavailable */
    public function create(BankAccount $account, BankAuditLine $line): BankTransaction
    {
        return $this->ledger->record($account, [
            'direction' => (float) $line->amount > 0 ? 'in' : 'out', 'transaction_date' => $line->line_date->toDateString(),
            'amount' => abs((float) $line->amount), 'purpose' => Str::limit((string) $line->description, 250, '…'), 'reference' => __('Bank çıxarışı'),
        ]);
    }

    /** A bank line that is an expense: the expense of the category, paid from the account — it books the movement itself. */
    public function expense(BankAccount $account, BankAuditLine $line, int $categoryId): Expense
    {
        return $this->expenses->save(new Expense, [
            'expense_date' => $line->line_date->toDateString(), 'category_id' => $categoryId,
            'description' => Str::limit((string) $line->description ?: __('Bank çıxarışı'), 250, '…'),
            'amount' => abs((float) $line->amount), 'currency' => $account->currency,
            'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => $line->line_date->toDateString(), 'bank_account_id' => $account->id,
            'reference' => __('Bank çıxarışı'),
        ]);
    }

    /** @throws RateUnavailable */
    public function amount(BankTransaction $tx, BankAuditLine $line): void
    {
        $this->ledger->update($tx, ['transaction_date' => $tx->transaction_date->toDateString(), 'amount' => abs((float) $line->amount)]);
    }

    /** @throws RateUnavailable */
    public function date(BankTransaction $tx, string $day): void
    {
        DB::transaction(function () use ($tx, $day) {
            $rate = fn (string $c) => $this->rates->rate($c, $day);
            if ($p = SupplierPayment::where('transaction_id', $tx->id)->first()) {
                $cbar = $rate($p->currency);
                $acc = $rate($p->account_currency);
                $cross = $cbar / $acc;
                $accCbar = round((float) $p->amount * $cross, 2);
                $diff = round((float) $p->account_amount - $accCbar, 2);
                $p->update(['payment_date' => $day, 'cbar_rate' => $cbar, 'cbar_account_rate' => $acc, 'cbar_cross' => $cross,
                    'account_amount_cbar' => $accCbar, 'difference' => $diff, 'difference_azn' => round($diff * $acc, 2)]);
            } elseif ($p = LogisticsPayment::with('act')->where('transaction_id', $tx->id)->first()) {
                $cbar = $rate($p->currency);
                $cbarAct = $rate($p->act->currency);
                $cross = $cbarAct / $cbar;
                $amountCbar = round((float) $p->act_amount * $cross, 2);
                $diff = round((float) $p->amount - $amountCbar, 2);
                $p->update(['payment_date' => $day, 'cbar_rate' => $cbar, 'cbar_act_rate' => $cbarAct, 'cbar_cross' => $cross,
                    'amount_cbar' => $amountCbar, 'difference' => $diff, 'difference_azn' => round($diff * $cbar, 2)]);
            } elseif ($e = Expense::where('bank_transaction_id', $tx->id)->first()) {
                $r = $rate($e->currency);
                $e->update(['expense_date' => $day, 'paid_at' => $day, 'cbar_rate' => $r, 'amount_azn' => round((float) $e->amount * $r, 2)]);
            } elseif ($x = CurrencyExchange::where('out_transaction_id', $tx->id)->orWhere('in_transaction_id', $tx->id)->first()) {
                $c = $this->exchanges->cbar($x->currency, $x->counter_currency, $day);
                $counterCbar = round((float) $x->amount * $c['cross'], 2);
                $diff = round($x->direction === 'buy' ? (float) $x->counter_amount - $counterCbar : $counterCbar - (float) $x->counter_amount, 2);
                $x->update(['exchange_date' => $day, 'cbar_rate' => $c['cbar'], 'cbar_counter_rate' => $c['cbar_counter'], 'cbar_cross' => $c['cross'],
                    'counter_amount_cbar' => $counterCbar, 'difference' => $diff, 'difference_azn' => round($diff * $c['cbar_counter'], 2)]);
            }
            // the movement — and, for a conversion or transfer, its other leg
            $legs = $tx->transfer_group ? BankTransaction::where('transfer_group', $tx->transfer_group)->get() : collect([$tx]);
            foreach ($legs as $leg) {
                $this->ledger->update($leg, ['transaction_date' => $day, 'amount' => (float) $leg->amount]);
            }
        });
    }

    public function delete(BankTransaction $tx): void
    {
        DB::transaction(function () use ($tx) {
            match (true) {
                (bool) ($p = SupplierPayment::where('transaction_id', $tx->id)->first()) => $this->supplier->delete($p),
                (bool) ($p = LogisticsPayment::where('transaction_id', $tx->id)->first()) => $this->logistics->deletePayment($p),
                (bool) ($e = Expense::where('bank_transaction_id', $tx->id)->first()) => $this->expenses->delete($e),
                (bool) ($x = CurrencyExchange::where('out_transaction_id', $tx->id)->orWhere('in_transaction_id', $tx->id)->first()) => $this->exchanges->delete($x),
                default => $this->ledger->delete($tx),
            };
        });
    }
}
