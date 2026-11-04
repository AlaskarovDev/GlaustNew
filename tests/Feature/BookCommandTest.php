<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\LogisticsAct;
use App\Support\Reports\CashflowReport;
use App\Support\Reports\ProfitLossReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AtfRow3;
use Tests\TestCase;

/** glaust:book: a statement's operations booked through the services, once; --undo takes them back. MZ keeps tax / assets out. */
class BookCommandTest extends TestCase
{
    use AtfRow3, RefreshDatabase;

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
        $this->assertSame([0, 0], $this->inTenant($admin, fn () => [BankAccount::where('name', 'Kapital Bank AZN')->count(), Category::where('name', 'Mənfəət Vergisi')->count()]), 'a dry run creates nothing');
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

    /** The 1C books: accruals (no money) on their MZ lines, a paid category taken out of MZ, an act moved to another day. */
    public function test_accruals_lines_and_act_dates(): void
    {
        [$admin] = $this->row3();
        $this->rates('2024-12-31', 1.7700, 0.016318);
        $this->rates('2025-01-31', 1.7659, 0.0175);
        $this->rates('2025-02-28', 1.7680, 0.0190);
        $this->inTenant($admin, fn () => Expense::create(['expense_date' => '2025-01-20', 'category_id' => Category::create(['scope' => 'expense', 'name' => 'Əmək haqqı', 'color' => '#64748b'])->id,
            'description' => 'maaş', 'amount' => 728, 'currency' => 'AZN', 'amount_azn' => 728, 'status' => 'paid', 'paid_at' => '2025-01-20', 'payment_method' => 'cash']));
        $payload = base64_encode(gzencode(json_encode([
            'categories' => ['Amortizasiya' => ['expense', '227.10'], 'ERTINS' => ['expense', '232.1'], 'Əmək haqqı (hesablama)' => ['expense', '221.1.2']],
            'pl_lines' => ['Əmək haqqı' => 'none'],
            'ops' => [
                ['type' => 'accrual', 'date' => '2025-01-31', 'amount' => 529.45, 'category' => 'Amortizasiya', 'description' => 'Amortizasiya'],
                ['type' => 'accrual', 'date' => '2025-01-31', 'amount' => 1000, 'category' => 'ERTINS', 'description' => 'Kenar teskilatlarin xidmetleri'],
                ['type' => 'accrual', 'date' => '2025-01-31', 'amount' => 816, 'category' => 'Əmək haqqı (hesablama)', 'description' => 'Əmək haqqı hesablanması'],
                ['type' => 'act_date', 'act' => 'VAM25006583', 'date' => '2025-02-10'],
            ],
        ])));
        $args = ['company' => (string) $admin->company_id, 'payload' => $payload, '--tag' => 'MZ1C'];

        $this->artisan('glaust:book', $args + ['--dry' => true])->expectsOutputToContain('Akt VAM25006583: 2025-01-28 → 2025-02-10')
            ->expectsOutputToContain('MZ sətri: Əmək haqqı: — → none')->expectsOutputToContain('Yoxlama: 0 xərc, 0 köçürmə, 0 daxilolma, 3 hesablama, 1 akt tarixi')->assertSuccessful();
        $this->assertSame('2025-01-28', $this->inTenant($admin, fn () => LogisticsAct::firstOrFail()->act_date->toDateString()), 'a dry run changes nothing');
        $this->artisan('glaust:book', $args)->expectsOutputToContain('Yazıldı: 0 xərc, 0 köçürmə, 0 daxilolma, 3 hesablama, 1 akt tarixi; artıq var: 0.')->assertSuccessful();
        $this->artisan('glaust:book', $args)->expectsOutputToContain('artıq var: 4.')->assertSuccessful();

        $this->inTenant($admin, function () {
            $this->assertSame(3, Expense::where('status', 'accrued')->whereNull('bank_transaction_id')->count());
            $pl = (new ProfitLossReport)->build('2025-01', '2025-02');
            $l = fn ($code, $m) => $pl['lines'][$code]['values'][$m];
            $this->assertSame([0.0, round(9898036.68 * 0.017526, 2)], [$l('217.2', '2025-01'), $l('217.2', '2025-02')], 'the sale moves with its act');
            $this->assertSame(529.45, $l('227.10', '2025-01'));
            $this->assertSame(1000.0, $l('232.1', '2025-01'), 'an expense on 232.1, the Trade has gone to February');
            $this->assertSame(816.0, $l('221.1.2', '2025-01'), 'the accrual, not the 728 paid');
            // an expense on the last day of the period counts (the day is stored with its time)
            $jan = (new ProfitLossReport)->build('2025-01', '2025-01');
            $this->assertSame([529.45, 816.0], [$jan['lines']['227.10']['total'], $jan['lines']['221.1.2']['total']]);
            // the cash flow: the 728 paid stays, the accruals are not money
            $cf = (new CashflowReport)->build('2025-01', '2025-02');
            $labels = collect($cf['payments'])->pluck('label');
            $this->assertSame(728.0, collect($cf['payments'])->firstWhere('label', 'Əmək haqqı')['total']);
            $this->assertFalse($labels->contains('Amortizasiya') || $labels->contains('ERTINS') || $labels->contains('Əmək haqqı (hesablama)'));
        });

        $this->artisan('glaust:book', ['company' => (string) $admin->company_id, '--tag' => 'MZ1C', '--undo' => true])->expectsOutputToContain('Geri alındı: 3 xərc')->assertSuccessful();
    }
}
