<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CurrencyExchange;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Executes a currency purchase / sale: computes the counter amount at CBAR and at the bank's rate,
 * records the difference, and moves the money as a conversion pair between the two accounts.
 */
class CurrencyExchangeService
{
    public function __construct(private BankLedger $ledger, private CurrencyRates $rates) {}

    /** @return array{cbar: float, cbar_counter: float, cross: float} AZN rates of the day and the CBAR cross */
    public function cbar(string $currency, string $counter, string $date): array
    {
        $a = $this->rates->rate($currency, $date);
        $b = $this->rates->rate($counter, $date);

        return ['cbar' => $a, 'cbar_counter' => $b, 'cross' => $a / $b];
    }

    public function execute(array $d): CurrencyExchange
    {
        $buy = $d['direction'] === 'buy';
        $from = BankAccount::findOrFail($d['from_account_id']);
        $to = BankAccount::findOrFail($d['to_account_id']);
        // buy: pay from a counter-currency account into a currency account; sell: the other way round.
        $fromCur = $buy ? $d['counter_currency'] : $d['currency'];
        $toCur = $buy ? $d['currency'] : $d['counter_currency'];
        $errors = [];
        if ($from->currency !== $fromCur) {
            $errors['from_account_id'] = __('Bu hesab :v1 hesabıdır; :v2 hesabı seçin.', ['v1' => $from->currency, 'v2' => $fromCur]);
        }
        if ($to->currency !== $toCur) {
            $errors['to_account_id'] = __('Bu hesab :v1 hesabıdır; :v2 hesabı seçin.', ['v1' => $to->currency, 'v2' => $toCur]);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $c = $this->cbar($d['currency'], $d['counter_currency'], $d['exchange_date']);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['exchange_date' => $e->getMessage()]);
        }
        $amount = round((float) $d['amount'], 2);
        $bankRate = (float) $d['bank_rate'];
        $counterCbar = round($amount * $c['cross'], 2);
        $counterBank = round($amount * $bankRate, 2);
        $difference = round($buy ? $counterBank - $counterCbar : $counterCbar - $counterBank, 2);

        return DB::transaction(function () use ($d, $buy, $from, $to, $c, $amount, $bankRate, $counterCbar, $counterBank, $difference) {
            $label = ($buy ? 'Valyuta alışı: ' : 'Valyuta satışı: ').number_format($amount, 2, '.', ' ').' '.$d['currency'].' / bank kursu '.rtrim(rtrim(number_format($bankRate, 6, '.', ''), '0'), '.');
            [$out, $in] = $this->ledger->transfer($from, $to, $d['exchange_date'],
                $buy ? $counterBank : $amount, $buy ? $amount : $counterBank,
                ['purpose' => $label, 'reference' => $d['reference'] ?? null]);

            return CurrencyExchange::create([
                'exchange_date' => $d['exchange_date'], 'direction' => $d['direction'],
                'currency' => $d['currency'], 'amount' => $amount, 'counter_currency' => $d['counter_currency'],
                'cbar_rate' => $c['cbar'], 'cbar_counter_rate' => $c['cbar_counter'], 'cbar_cross' => $c['cross'], 'bank_rate' => $bankRate,
                'counter_amount_cbar' => $counterCbar, 'counter_amount' => $counterBank,
                'difference' => $difference, 'difference_azn' => round($difference * $c['cbar_counter'], 2),
                'from_account_id' => $from->id, 'to_account_id' => $to->id, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id,
                'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id(),
                'project_id' => $d['project_id'] ?? null, 'deal_id' => $d['deal_id'] ?? null,
            ]);
        });
    }

    public function delete(CurrencyExchange $x): void
    {
        DB::transaction(function () use ($x) {
            if ($x->out_transaction_id && $tx = BankTransaction::find($x->out_transaction_id)) {
                $this->ledger->delete($tx); // takes the paired leg with it
            }
            $x->delete();
        });
    }
}
