<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Services\CurrencyExchangeService;
use App\Services\ExpenseService;
use App\Support\Reports\CashflowReport;
use App\Support\Reports\ProfitLossReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** glaust:bank-fix: the bank side brought to the statement through the services, all or nothing; --dry rolls back. */
class BankFixCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_brings_the_accounts_to_the_statement(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$sellTx, $feeTx] = $this->inTenant($admin, function () {
            $azn = BankAccount::create(['name' => 'AZN Hesabı', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'opening_balance' => 100000, 'is_active' => true]);
            $rub = BankAccount::create(['name' => 'RUBL Hesabı', 'bank_name' => 'Turan Bank', 'currency' => 'RUB', 'opening_balance' => 100000, 'is_active' => true]);
            $x = app(CurrencyExchangeService::class)->execute(['exchange_date' => '2025-03-03', 'direction' => 'sell', 'currency' => 'RUB', 'counter_currency' => 'AZN',
                'amount' => 100000, 'bank_rate' => 0.02, 'from_account_id' => $rub->id, 'to_account_id' => $azn->id]);
            $fee = app(ExpenseService::class)->save(new Expense, ['expense_date' => '2025-03-03', 'category_id' => Category::create(['scope' => 'expense', 'name' => 'Bank komissiyası', 'color' => '#64748b'])->id,
                'description' => 'komissiya', 'amount' => 7, 'currency' => 'AZN', 'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => '2025-03-03', 'bank_account_id' => $azn->id]);

            return [$x->out_transaction_id, $fee->bank_transaction_id];
        });
        $payload = base64_encode(gzencode(json_encode(['ops' => [
            ['type' => 'account', 'name' => 'USD Hesabı', 'bank_name' => 'Turan Bank', 'currency' => 'USD'],
            ['type' => 'exchange', 'date' => '2025-03-04', 'direction' => 'buy', 'currency' => 'USD', 'amount' => 1000, 'counter' => 1702, 'from' => 'AZN Hesabı', 'to' => 'USD Hesabı'],
            ['type' => 'expense', 'date' => '2025-03-04', 'account' => 'USD Hesabı', 'amount' => 600, 'category' => 'ERTINS (ödəniş)', 'pl_line' => 'none', 'description' => 'ERTINS'],
            ['type' => 'redo', 'tx' => $sellTx, 'counter' => 1990],
            ['type' => 'fees', 'replace' => [$feeTx], 'new' => [['date' => '2025-03-04', 'account' => 'AZN Hesabı', 'amount' => 5.5, 'description' => 'KÖÇ KOM 600 USD',
                'category' => 'Valyuta köçürmə komissiyası', 'pl_line' => '221.13']]],
        ]])));
        $args = ['company' => (string) $admin->company_id, 'payload' => $payload];

        $this->artisan('glaust:bank-fix', $args + ['--dry' => true])->expectsOutputToContain('Yoxlama: 5 əməliyyat keçdi')->assertSuccessful();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankAccount::where('name', 'USD Hesabı')->count()), 'a dry run writes nothing');
        $this->artisan('glaust:bank-fix', $args)->expectsOutputToContain('Yazıldı: 5 əməliyyat.')->assertSuccessful();

        $this->inTenant($admin, function () {
            $this->assertSame(400.0, BankAccount::where('name', 'USD Hesabı')->first()->balance());
            $this->assertSame(round(100000 + 1990 - 1702 - 5.5, 2), BankAccount::where('name', 'AZN Hesabı')->first()->balance());
            $this->assertSame(1, CurrencyExchange::where('direction', 'sell')->count(), 'the conversion booked again, not twice');
            $this->assertSame(1990.0, (float) CurrencyExchange::where('direction', 'sell')->value('counter_amount'));
            $this->assertSame(0, Expense::where('description', 'komissiya')->count());
            $cf = (new CashflowReport)->build('2025-03', '2025-03');
            $this->assertSame(5.5, collect($cf['payments'])->firstWhere('label', 'Valyuta köçürmə komissiyası')['total']);
            $this->assertSame(1020.0, collect($cf['payments'])->firstWhere('label', 'ERTINS (ödəniş)')['total']);
            $pl = (new ProfitLossReport)->build('2025-03', '2025-03');
            $this->assertSame(0.0, $pl['lines']['232']['total'], 'the payment to ERTINS stays out of MZ');
        });

        $bad = base64_encode(gzencode(json_encode(['ops' => [['type' => 'account', 'name' => 'X', 'bank_name' => 'B', 'currency' => 'GBP'], ['type' => 'date', 'tx' => 999999, 'date' => '2025-03-05']]])));
        $this->artisan('glaust:bank-fix', ['company' => (string) $admin->company_id, 'payload' => $bad])->expectsOutputToContain('Əməliyyat 2:')->assertFailed();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankAccount::where('name', 'X')->count()), 'all or nothing');
    }
}
