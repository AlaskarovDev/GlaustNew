<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves an expense and keeps its bank side in step: paid by transfer = one outgoing movement on
 * the chosen account on the payment date (valued at CBAR by the ledger); changing the payment
 * moves or removes that movement, unpaid/cash expenses have none.
 */
class ExpenseService
{
    public function __construct(private BankLedger $ledger, private CurrencyRates $rates) {}

    public function save(Expense $expense, array $data): Expense
    {
        $paid = $data['status'] === 'paid';
        $bank = $paid && ($data['payment_method'] ?? null) === 'bank';
        $account = $bank ? BankAccount::findOrFail($data['bank_account_id']) : null;
        if ($account && $account->currency !== $data['currency']) {
            throw ValidationException::withMessages(['bank_account_id' => "Seçilən hesab {$account->currency} hesabıdır, xərc isə {$data['currency']} ilə. Eyni valyutalı hesab seçin."]);
        }

        $rate = $this->rates->tryRate($data['currency'], $data['expense_date']);
        $values = [
            'expense_date' => $data['expense_date'], 'category_id' => $data['category_id'] ?? null, 'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2), 'currency' => $data['currency'],
            'cbar_rate' => $rate, 'amount_azn' => $rate === null ? null : round((float) $data['amount'] * $rate, 2),
            'counterparty_id' => $data['counterparty_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'deal_id' => $data['deal_id'] ?? null,
            'status' => $data['status'], 'due_date' => $paid ? null : ($data['due_date'] ?? null),
            'payment_method' => $paid ? $data['payment_method'] : null, 'paid_at' => $paid ? $data['paid_at'] : null,
            'bank_account_id' => $account?->id, 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
        ];

        try {
            return DB::transaction(function () use ($expense, $values, $account) {
                $expense->fill($values);
                if (! $expense->exists) {
                    $expense->created_by = auth()->id();
                }
                $expense->save();
                $this->syncBank($expense, $account);

                return $expense;
            });
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['paid_at' => $e->getMessage().' Xərc yadda saxlanmadı.']);
        }
    }

    public function delete(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $expense->transaction?->delete();
            $expense->update(['bank_transaction_id' => null]);
            $expense->delete();
        });
    }

    private function syncBank(Expense $expense, ?BankAccount $account): void
    {
        $tx = $expense->bank_transaction_id ? $expense->transaction()->first() : null;
        if (! $account) {
            if ($tx) {
                $tx->delete();
                $expense->update(['bank_transaction_id' => null]);
            }

            return;
        }
        $data = [
            'direction' => 'out', 'transaction_date' => $expense->paid_at->toDateString(), 'amount' => (float) $expense->amount,
            'counterparty_id' => $expense->counterparty_id, 'project_id' => $expense->project_id, 'deal_id' => $expense->deal_id,
            'purpose' => 'Xərc: '.trim(($expense->category?->name ? $expense->category->name.' — ' : '').$expense->description),
            'reference' => $expense->reference,
        ];
        if ($tx && $tx->bank_account_id === $account->id) {
            $this->ledger->update($tx, $data);

            return;
        }
        $tx?->delete();
        $new = $this->ledger->record($account, $data);
        $expense->update(['bank_transaction_id' => $new->id]);
    }
}
