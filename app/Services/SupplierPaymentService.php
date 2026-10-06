<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\SupplierPayment;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pays a deal's seller: the amount in the invoice currency, debited from any of our accounts at the
 * bank's rate (CBAR kept beside it), plus the bank's fee (EUR: 0.25 %, kept within 25–300 EUR) booked as a "Bank komissiyası" expense on
 * the same account. Everything is stored on the SupplierPayment for the reports.
 */
class SupplierPaymentService
{
    public const FEE_CATEGORY = 'Bank komissiyası';

    public function __construct(private BankLedger $ledger, private CurrencyRates $rates, private ExpenseService $expenses) {}

    public function pay(Deal $deal, array $d): SupplierPayment
    {
        if (! $deal->supplier_id) {
            throw ValidationException::withMessages(['amount' => __('Əvvəlcə Trade-də satıcını seçin.')]);
        }
        $account = BankAccount::findOrFail($d['bank_account_id']);
        $cur = $d['currency'];
        $same = $account->currency === $cur;
        if (! $same && empty($d['bank_rate'])) {
            throw ValidationException::withMessages(['bank_rate' => __('Hesab :v1, ödəniş :v2: bankın kursunu daxil edin (1 :v3 = ? :v4).', ['v1' => $account->currency, 'v2' => $cur, 'v3' => $cur, 'v4' => $account->currency])]);
        }

        try {
            $cbar = $this->rates->rate($cur, $d['payment_date']);
            $cbarAcc = $this->rates->rate($account->currency, $d['payment_date']);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['payment_date' => $e->getMessage().__(' Ödəniş edilmədi.')]);
        }
        $amount = round((float) $d['amount'], 2);
        $cross = $cbar / $cbarAcc;
        $bankRate = $same ? 1.0 : (float) $d['bank_rate'];
        $accCbar = round($amount * $cross, 2);
        $accBank = round($amount * $bankRate, 2);
        $diff = round($accBank - $accCbar, 2);

        $rule = SupplierPayment::feeFor($cur, $amount);
        $fee = isset($d['fee_amount']) && $d['fee_amount'] !== null ? round((float) $d['fee_amount'], 2) : ($rule['amount'] ?? 0.0);

        // The fee (in the payment currency) comes off the payment account at the bank's rate, or off any other
        // account chosen for it — then converted to that account's currency at CBAR of the payment date.
        $feeAccount = ! empty($d['fee_account_id']) ? BankAccount::findOrFail($d['fee_account_id']) : $account;
        if ($feeAccount->id === $account->id) {
            $feeAcc = round($fee * $bankRate, 2);
        } else {
            try {
                $feeAcc = round($fee * $cbar / $this->rates->rate($feeAccount->currency, $d['payment_date']), 2);
            } catch (RateUnavailable $e) {
                throw ValidationException::withMessages(['fee_account_id' => $e->getMessage()]);
            }
        }

        return DB::transaction(function () use ($deal, $d, $account, $feeAccount, $cur, $amount, $cbar, $cbarAcc, $cross, $bankRate, $accCbar, $accBank, $diff, $rule, $fee, $feeAcc) {
            $purpose = ($d['purpose'] ?? null) ?: 'Trade '.$deal->code.' üzrə satıcıya ödəniş: '.number_format($amount, 2, '.', ' ').' '.$cur;
            $tx = $this->ledger->record($account, [
                'direction' => 'out', 'transaction_date' => $d['payment_date'], 'amount' => $accBank,
                'counterparty_id' => $deal->supplier_id, 'contract_id' => $deal->purchase_contract_id, 'project_id' => $deal->project_id, 'deal_id' => $deal->id,
                'purpose' => $purpose, 'reference' => $d['reference'] ?? null,
            ]);

            $feeExpense = null;
            if ($feeAcc > 0) {
                $category = Category::firstOrCreate(['scope' => 'expense', 'name' => self::FEE_CATEGORY], ['color' => '#64748b']);
                $feeExpense = $this->expenses->save(new Expense, [
                    'expense_date' => $d['payment_date'], 'category_id' => $category->id,
                    'description' => 'Bank komissiyası: satıcıya köçürmə '.number_format($amount, 2, '.', ' ').' '.$cur.' ('.$deal->code.')'
                        .($rule ? ', '.rtrim(rtrim(number_format($rule['percent'], 2), '0'), '.').'% ('.rtrim(rtrim(number_format($rule['minimum'], 2), '0'), '.').'–'.rtrim(rtrim(number_format($rule['maximum'] ?? 0, 2), '0'), '.').' '.$cur.')' : ''),
                    'amount' => $feeAcc, 'currency' => $feeAccount->currency, 'project_id' => $deal->project_id, 'deal_id' => $deal->id,
                    'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => $d['payment_date'], 'bank_account_id' => $feeAccount->id,
                    'reference' => $d['reference'] ?? null,
                ]);
            }

            return SupplierPayment::create([
                'deal_id' => $deal->id, 'counterparty_id' => $deal->supplier_id, 'contract_id' => $deal->purchase_contract_id,
                'payment_date' => $d['payment_date'], 'currency' => $cur, 'amount' => $amount,
                'bank_account_id' => $account->id, 'account_currency' => $account->currency,
                'cbar_rate' => $cbar, 'cbar_account_rate' => $cbarAcc, 'cbar_cross' => $cross, 'bank_rate' => $bankRate,
                'account_amount_cbar' => $accCbar, 'account_amount' => $accBank, 'difference' => $diff, 'difference_azn' => round($diff * $cbarAcc, 2),
                'fee_percent' => $rule['percent'] ?? null, 'fee_minimum' => $rule['minimum'] ?? null, 'fee_maximum' => $rule['maximum'] ?? null, 'fee_amount' => $fee, 'fee_account_amount' => $feeAcc,
                'fee_account_id' => $feeAccount->id, 'fee_account_currency' => $feeAccount->currency,
                'transaction_id' => $tx->id, 'fee_expense_id' => $feeExpense?->id,
                'reference' => $d['reference'] ?? null, 'purpose' => $purpose, 'created_by' => auth()->id(),
            ]);
        });
    }

    public function delete(SupplierPayment $p): void
    {
        DB::transaction(function () use ($p) {
            $p->transaction?->delete();
            if ($p->feeExpense) {
                $this->expenses->delete($p->feeExpense);
            }
            $p->delete();
        });
    }
}
