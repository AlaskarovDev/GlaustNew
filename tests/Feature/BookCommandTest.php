<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Support\Reports\CashflowReport;
use App\Support\Reports\ProfitLossReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** glaust:book: a statement's operations booked through the services, once; --undo takes them back. MZ keeps tax / assets out. */
class BookCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_books_once_and_undoes(): void
    {
        $admin = $this->makeCompany();
        $this->inTenant($admin, fn () => BankAccount::create(['name' => 'AZN Hesabı', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'opening_balance' => 1000000, 'is_active' => true]));
        $payload = base64_encode(gzencode(json_encode([
            'accounts' => [['name' => 'Kapital Bank AZN', 'bank_name' => 'Kapital Bank', 'currency' => 'AZN']],
            'categories' => ['Mənfəət Vergisi' => ['expense', 'none'], 'Faiz gəlirləri (kapital)' => ['income', '212'], 'NEXT 26 MMC' => ['expense', '232']],
            'ops' => [
                ['type' => 'expense', 'date' => '2025-05-22', 'amount' => 21400, 'category' => 'Mənfəət Vergisi', 'description' => 'MV ödəməsi', 'account' => 'AZN Hesabı'],
                ['type' => 'expense', 'date' => '2025-10-15', 'amount' => 800, 'category' => 'NEXT 26 MMC', 'description' => 'NEXT 26', 'account' => 'AZN Hesabı'],
                ['type' => 'transfer', 'date' => '2025-07-09', 'amount' => 400000, 'from' => 'AZN Hesabı', 'to' => 'Kapital Bank AZN', 'description' => 'Hesabın möhkəmləndirilməsi'],
                ['type' => 'income', 'date' => '2025-07-31', 'amount' => 977.77, 'account' => 'Kapital Bank AZN', 'category' => 'Faiz gəlirləri (kapital)', 'description' => 'Faiz'],
            ],
        ])));
        $args = ['company' => (string) $admin->company_id, 'payload' => $payload, '--tag' => 'CF2025'];

        $this->artisan('glaust:book', $args + ['--dry' => true])->expectsOutputToContain('Yoxlama: 2 xərc, 1 köçürmə, 1 daxilolma')->assertSuccessful();
        $this->artisan('glaust:book', $args)->expectsOutputToContain('Yazıldı: 2 xərc, 1 köçürmə, 1 daxilolma; artıq var: 0.')->assertSuccessful();
        $this->artisan('glaust:book', $args)->expectsOutputToContain('artıq var: 4.')->assertSuccessful();

        $this->inTenant($admin, function () {
            $kapital = BankAccount::where('name', 'Kapital Bank AZN')->firstOrFail();
            $this->assertSame(400977.77, $kapital->balance());
            $this->assertSame(1000000 - 21400 - 800 - 400000.0, BankAccount::where('name', 'AZN Hesabı')->first()->balance());
            $this->assertSame('none', Category::where('name', 'Mənfəət Vergisi')->value('pl_line'));

            // MZ: the profit tax stays out, the interest is 212, NEXT 26 on 232
            $pl = (new ProfitLossReport)->build('2025-05', '2025-10');
            $this->assertSame(977.77, $pl['lines']['212']['total']);
            $this->assertSame(800.0, $pl['lines']['232']['total']);
            $this->assertSame(800.0, round(array_sum($pl['out']), 2), 'the 21 400 tax is not an expense of the period');
            // cash flow: the interest under its category, the transfer is not cash flow
            $cf = (new CashflowReport)->build('2025-05', '2025-10');
            $this->assertSame(977.77, collect($cf['income'])->firstWhere('label', 'Faiz gəlirləri (kapital)')['total']);
            $this->assertSame(22200.0, round(array_sum($cf['out']), 2));
        });

        $this->artisan('glaust:book', ['company' => (string) $admin->company_id, '--tag' => 'CF2025', '--undo' => true])->expectsOutputToContain('Geri alındı: 2 xərc')->assertSuccessful();
        $this->inTenant($admin, function () {
            $this->assertSame(0, Expense::count());
            $this->assertSame(0, BankTransaction::count());
        });
        $this->artisan('glaust:book', ['company' => '999999', 'payload' => $payload])->assertFailed();
    }
}
