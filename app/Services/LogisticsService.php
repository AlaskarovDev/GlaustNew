<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\Reminder;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Support\Finance\BankFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logistika aktı and its payment. The act is valued at the CBAR rates of its date in AZN, RUB and
 * EUR; it is paid today or later (a reminder for the planned day), in one or more parts — each part
 * a share of the act, in its own currency, from an account in that currency, at the bank's rate,
 * with the bank's fee added on top and booked as a "Bank komissiyası" expense.
 */
class LogisticsService
{
    public function __construct(private BankLedger $ledger, private CurrencyRates $rates, private ExpenseService $expenses) {}

    public function createAct(Deal $deal, array $d, array $parts = []): LogisticsAct
    {
        $rate = fn (string $cur) => $this->rate($cur, $d['act_date'], 'act_date');
        $cbar = $rate($d['currency']);
        $rub = $rate('RUB');
        $eur = $rate('EUR');
        $amount = round((float) $d['amount'], 2);
        $later = ($d['payment_plan'] ?? 'later') === 'later';

        return DB::transaction(function () use ($deal, $d, $parts, $cbar, $rub, $eur, $amount, $later) {
            $act = LogisticsAct::create([
                'deal_id' => $deal->id, 'invoice_id' => $d['invoice_id'] ?? null, 'counterparty_id' => $d['counterparty_id'] ?? null,
                'act_number' => $d['act_number'], 'act_date' => $d['act_date'], 'currency' => $d['currency'], 'amount' => $amount,
                'cbar_rate' => $cbar, 'cbar_rub' => $rub, 'cbar_eur' => $eur,
                'amount_azn' => round($amount * $cbar, 2), 'amount_rub' => round($amount * $cbar / $rub, 2), 'amount_eur' => round($amount * $cbar / $eur, 2),
                'logistics_invoice_number' => $d['logistics_invoice_number'] ?? null, 'logistics_invoice_date' => $d['logistics_invoice_date'] ?? null,
                'payment_plan' => $later ? 'later' : 'today', 'planned_date' => $later ? ($d['planned_date'] ?? null) : null,
                'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
            ]);
            if ($later && ! empty($d['remind']) && $act->planned_date) {
                $this->remind($act);
            }
            if (! $later && $parts) {
                $this->pay($act, $parts, today()->toDateString(), $d['reference'] ?? null);
            }

            return $act->fresh();
        });
    }

    /**
     * @param  list<array{act_amount: float|string, currency: string, bank_account_id: int, bank_rate?: float|string|null, fee_amount?: float|string|null, payment_date?: string|null}>  $parts
     *         each part may carry its own payment date (rates and fee of that day); $date is the default
     */
    public function pay(LogisticsAct $act, array $parts, string $date, ?string $reference = null): array
    {
        $act->loadMissing('payments', 'deal', 'counterparty');
        // A part given as an amount in its own currency settles amount / bank rate of the act.
        foreach ($parts as $i => &$p) {
            if ((! isset($p['act_amount']) || $p['act_amount'] === null) && isset($p['amount'])) {
                $same = $p['currency'] === $act->currency;
                if (! $same && empty($p['bank_rate'])) {
                    throw ValidationException::withMessages(["parts.$i.bank_rate" => ($i + 1).__('-ci hissə: bankın kursunu daxil edin (1 :v1 = ? :v2).', ['v1' => $act->currency, 'v2' => $p['currency']])]);
                }
                $p['act_amount'] = round((float) $p['amount'] / ($same ? 1 : (float) $p['bank_rate']), 2);
                $p['paid'] = round((float) $p['amount'], 2);
            }
        }
        unset($p);
        $total = round(array_sum(array_map(fn ($p) => (float) $p['act_amount'], $parts)), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['parts' => __('Ödəniş hissələrinin məbləğini daxil edin.')]);
        }
        if ($total > $act->remaining() + 0.05) {
            throw ValidationException::withMessages(['parts' => __('Hissələrin cəmi (').money($total, $act->currency).__(') aktın qalığından (').money($act->remaining(), $act->currency).__(') çoxdur.')]);
        }

        return DB::transaction(function () use ($act, $parts, $date, $reference) {
            $out = [];
            foreach (array_values($parts) as $i => $p) {
                $partDate = ! empty($p['payment_date']) ? $p['payment_date'] : $date;
                $cbarAct = $this->rate($act->currency, $partDate, "parts.$i.payment_date");
                $eur = $this->rate('EUR', $partDate, "parts.$i.payment_date");
                $account = BankAccount::findOrFail($p['bank_account_id']);
                $cur = $p['currency'];
                if ($account->currency !== $cur) {
                    throw ValidationException::withMessages(["parts.$i.bank_account_id" => ($i + 1).__('-ci hissə: hesab :v1, ödəniş :v2 — :v3 hesabı seçin.', ['v1' => $account->currency, 'v2' => $cur, 'v3' => $cur])]);
                }
                $share = round((float) $p['act_amount'], 2);
                $cbar = $this->rate($cur, $partDate, "parts.$i.payment_date");
                $same = $cur === $act->currency;
                if (! $same && empty($p['bank_rate'])) {
                    throw ValidationException::withMessages(["parts.$i.bank_rate" => ($i + 1).__('-ci hissə: bankın kursunu daxil edin (1 :v1 = ? :v2).', ['v1' => $act->currency, 'v2' => $cur])]);
                }
                $cross = $cbarAct / $cbar;
                $bankRate = $same ? 1.0 : (float) $p['bank_rate'];
                $amount = isset($p['paid']) ? $p['paid'] : round($share * $bankRate, 2); // what actually left the account
                $amountCbar = round($share * $cross, 2);
                $rule = BankFee::for($cur, $amount, $cbar, $eur);
                $fee = isset($p['fee_amount']) && $p['fee_amount'] !== null && $p['fee_amount'] !== '' ? round((float) $p['fee_amount'], 2) : ($rule['amount'] ?? 0.0);

                $tx = $this->ledger->record($account, [
                    'direction' => 'out', 'transaction_date' => $partDate, 'amount' => $amount,
                    'counterparty_id' => $act->counterparty_id, 'project_id' => $act->deal->project_id, 'deal_id' => $act->deal_id,
                    'purpose' => 'Logistika aktı '.$act->act_number.' ('.$act->deal->code.'): '.number_format($share, 2, '.', ' ').' '.$act->currency,
                    'reference' => $reference,
                ]);
                $feeExpense = null;
                if ($fee > 0) {
                    $category = Category::firstOrCreate(['scope' => 'expense', 'name' => SupplierPaymentService::FEE_CATEGORY], ['color' => '#64748b']);
                    $feeExpense = $this->expenses->save(new Expense, [
                        'expense_date' => $partDate, 'category_id' => $category->id,
                        'description' => 'Bank komissiyası: logistika aktı '.$act->act_number.', köçürmə '.number_format($amount, 2, '.', ' ').' '.$cur,
                        'amount' => $fee, 'currency' => $cur, 'counterparty_id' => null, 'project_id' => $act->deal->project_id, 'deal_id' => $act->deal_id,
                        'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => $partDate, 'bank_account_id' => $account->id, 'reference' => $reference,
                    ]);
                }
                $out[] = LogisticsPayment::create([
                    'logistics_act_id' => $act->id, 'deal_id' => $act->deal_id, 'payment_date' => $partDate,
                    'act_amount' => $share, 'currency' => $cur, 'cbar_act_rate' => $cbarAct, 'cbar_rate' => $cbar, 'cbar_cross' => $cross, 'bank_rate' => $bankRate,
                    'amount_cbar' => $amountCbar, 'amount' => $amount, 'difference' => round($amount - $amountCbar, 2), 'difference_azn' => round(($amount - $amountCbar) * $cbar, 2),
                    'fee_percent' => $rule['percent'] ?? null, 'fee_minimum' => $rule['minimum'] ?? null, 'fee_maximum' => $rule['maximum'] ?? null,
                    'fee_amount' => $fee, 'fee_azn' => round($fee * $cbar, 2), 'fee_eur' => round($fee * $cbar / $eur, 2),
                    'bank_account_id' => $account->id, 'transaction_id' => $tx->id, 'fee_expense_id' => $feeExpense?->id,
                    'reference' => $reference, 'created_by' => auth()->id(),
                ]);
            }
            if ($act->reminder_id && $act->fresh()->remaining() <= 0) {
                Reminder::whereKey($act->reminder_id)->whereNull('read_at')->update(['read_at' => now()]);
            }

            return $out;
        });
    }

    public function remind(LogisticsAct $act): Reminder
    {
        $act->loadMissing('deal');
        $r = Reminder::firstOrCreate(['dedupe_key' => 'logistics-act:'.$act->id.':'.$act->planned_date->toDateString()], [
            'user_id' => auth()->id(), 'source' => 'logistics_payment',
            'title' => 'Logistika ödənişi: akt '.$act->act_number, 'body' => money($act->amount, $act->currency).' · Trade '.$act->deal->code,
            'url' => route('deals.show', [$act->deal_id, 'tab' => 'logistics'], false), 'remindable_type' => 'logistics_act', 'remindable_id' => $act->id,
            'remind_at' => $act->planned_date->copy()->setTime(9, 0),
        ]);
        $act->update(['reminder_id' => $r->id]);

        return $r;
    }

    public function deletePayment(LogisticsPayment $p): void
    {
        DB::transaction(function () use ($p) {
            $p->transaction?->delete();
            if ($p->feeExpense) {
                $this->expenses->delete($p->feeExpense);
            }
            $p->delete();
        });
    }

    public function deleteAct(LogisticsAct $act): void
    {
        DB::transaction(function () use ($act) {
            $act->payments->each(fn ($p) => $this->deletePayment($p));
            $act->reminder?->delete();
            $act->delete();
        });
    }

    private function rate(string $cur, string $date, string $field): float
    {
        try {
            return $this->rates->rate($cur, $date);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
