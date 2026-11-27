<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** glaust:trade-book: a year from bank statements, Trades included — dry run, booking, no second run, undo. */
class TradeBookCommandTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): string
    {
        $d = fn (int $back) => today()->subDays($back)->toDateString();

        return base64_encode(gzencode(json_encode([
            'accounts' => [
                ['key' => 'usd', 'name' => 'Bank USD', 'bank_name' => 'Bank A', 'iban' => 'AZ00TEST00000000000000000001', 'currency' => 'USD'],
                ['key' => 'azn', 'name' => 'Bank AZN', 'bank_name' => 'Bank A', 'iban' => 'AZ00TEST00000000000000000002', 'currency' => 'AZN'],
                ['key' => 'kb', 'name' => 'Bank 2 AZN', 'bank_name' => 'Bank B', 'currency' => 'AZN'],
            ],
            'counterparties' => [
                ['key' => 'buyer', 'name' => 'Alıcı LLC', 'type' => 'customer', 'country' => 'Rusiya'],
                ['key' => 'seller', 'name' => 'Satıcı Ltd', 'type' => 'supplier', 'country' => 'Çin'],
                ['key' => 'log', 'name' => 'Daşıyıcı TOO', 'type' => 'supplier', 'country' => 'Qazaxıstan'],
            ],
            'contracts' => [['key' => 'p', 'number' => 'P-100', 'date' => $d(30), 'party' => 'seller', 'kind' => 'purchase', 'subject' => 'Kağız', 'currency' => 'USD']],
            'project' => ['code' => 'T-2025', 'name' => 'Test 2025', 'currency' => 'USD'],
            'trades' => [[
                'key' => 't1', 'title' => 'Alıcı ← Satıcı', 'date' => $d(20), 'currency' => 'USD', 'sale_currency' => 'USD',
                'supplier' => 'seller', 'buyer' => 'buyer', 'purchase_contract' => 'p',
                'invoice' => ['number' => 'P-100', 'date' => $d(20), 'amount' => 1000],
                'final' => ['date' => $d(20), 'amount' => 1500, 'currency' => 'USD'],
                'receipts' => [['date' => $d(10), 'account' => 'usd', 'amount' => 1500, 'purpose' => 'Alıcının ödənişi']],
                'payments' => [['date' => $d(9), 'account' => 'usd', 'amount' => 1000, 'fee' => 30, 'reference' => 'Swift']],
                'logistics' => [['party' => 'log', 'number' => 'Inv.200', 'date' => $d(9), 'amount' => 200, 'pay' => ['date' => $d(8), 'account' => 'usd', 'amount' => 200, 'fee' => 10]]],
            ]],
            'ops' => [
                ['type' => 'exchange', 'date' => $d(7), 'direction' => 'sell', 'currency' => 'USD', 'amount' => 100, 'rate' => 1.697, 'from' => 'usd', 'to' => 'azn'],
                ['type' => 'expense', 'date' => $d(6), 'account' => 'azn', 'amount' => 50, 'category' => 'Vergilər', 'description' => 'ƏDV'],
                ['type' => 'transfer', 'date' => $d(5), 'from' => 'azn', 'to' => 'kb', 'amount' => 100, 'description' => 'Hesabın möhkəmləndirilməsi'],
            ],
            'expect' => ['usd' => 160, 'azn' => 19.7, 'kb' => 100],
        ])));
    }

    public function test_dry_run_book_and_undo(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $id = (string) $admin->company_id;

        $this->artisan('glaust:trade-book', ['company' => $id, 'payload' => $this->payload(), '--tag' => 'T25', '--dry' => true])
            ->expectsOutputToContain('✓ Bank A · Bank USD: sistem 160.00 / çıxarış 160.00')
            ->expectsOutputToContain('Qalıqlar çıxarışlarla eynidir')->assertSuccessful();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankAccount::count()), 'the dry run leaves nothing');

        $this->artisan('glaust:trade-book', ['company' => $id, 'payload' => $this->payload(), '--tag' => 'T25'])->expectsOutputToContain('Yazıldı.')->assertSuccessful();
        $this->inTenant($admin, function () {
            $deal = Deal::with('invoices', 'logisticsActs')->firstOrFail();
            $this->assertSame(['USD', 'USD', 'completed'], [$deal->currency, $deal->sale_currency, $deal->status]);
            $this->assertSame(1500.0, (float) $deal->invoices->first()->final_amount);
            $this->assertCount(1, $deal->logisticsActs);
            $this->assertSame(30.0, (float) SupplierPayment::firstOrFail()->fee_amount);
            $this->assertSame(1, CurrencyExchange::count());
            $this->assertSame(160.0, BankAccount::where('name', 'Bank USD')->first()->balance());
            $this->assertSame('AZ00TEST00000000000000000001', BankAccount::where('name', 'Bank USD')->first()->iban);
        });

        $this->artisan('glaust:trade-book', ['company' => $id, 'payload' => $this->payload(), '--tag' => 'T25'])->expectsOutputToContain('artıq yazılıb')->assertFailed();

        $this->artisan('glaust:trade-book', ['company' => $id, '--tag' => 'T25', '--undo' => true])->expectsOutputToContain('Geri alındı: 1 Trade')->assertSuccessful();
        $this->inTenant($admin, function () {
            $this->assertSame(0, Deal::count());
            $this->assertSame(0, BankTransaction::count());
            $this->assertSame(0, Expense::count());
            $this->assertSame(0, CurrencyExchange::count());
        });
    }
}
