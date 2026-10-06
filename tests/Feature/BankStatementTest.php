<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Services\BankLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bank hesabları grouped by bank, and the account statement with running balance. */
class BankStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_shows_every_movement_with_running_balance(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $acc = $this->inTenant($admin, function () {
            $acc = BankAccount::create(['name' => 'Əsas RUB', 'bank_name' => 'Kapital Bank', 'currency' => 'RUB', 'opening_balance' => 1000, 'is_active' => true]);
            BankAccount::create(['name' => 'Əsas EUR', 'bank_name' => 'Kapital Bank', 'currency' => 'EUR', 'is_active' => true]);
            BankAccount::create(['name' => 'Cari AZN', 'bank_name' => 'ABB', 'currency' => 'AZN', 'is_active' => true]);
            $ledger = app(BankLedger::class);
            $ledger->record($acc, ['direction' => 'in', 'transaction_date' => today()->subDays(9)->toDateString(), 'amount' => 5000, 'purpose' => 'Birinci mədaxil']);
            $ledger->record($acc, ['direction' => 'out', 'transaction_date' => today()->subDays(5)->toDateString(), 'amount' => 1200, 'purpose' => 'Komissiya']);
            $ledger->record($acc, ['direction' => 'in', 'transaction_date' => today()->subDays(2)->toDateString(), 'amount' => 300, 'purpose' => 'Son mədaxil']);

            return $acc;
        });
        $this->actingAs($admin);

        // Grouped: banks first, their currency accounts inside.
        $this->get(route('bank.accounts.index'))->assertOk()
            ->assertSeeInOrder(['ABB', 'Cari AZN', 'Kapital Bank', 'Əsas EUR', 'Əsas RUB'])->assertSee('Çıxarış');

        // Whole period: 1000 + 5000 - 1200 + 300.
        $this->get(route('bank.accounts.statement', $acc))->assertOk()
            ->assertSeeInOrder(['Birinci mədaxil', 'Komissiya', 'Son mədaxil'])
            ->assertSee(money(6000, 'RUB'))->assertSee(money(4800, 'RUB'))->assertSee(money(5100, 'RUB'))
            ->assertDontSee(money(5100))->assertDontSee(money(6000));   // a RUB account: no manat sign on its amounts

        // From a date: the earlier movement is folded into the opening balance.
        $this->get(route('bank.accounts.statement', [$acc, 'from' => today()->subDays(6)->toDateString()]))->assertOk()
            ->assertDontSee('Birinci mədaxil')->assertSee(money(6000, 'RUB'))->assertSee(money(5100, 'RUB'));

        $this->get(route('bank.accounts.statement', [$acc, 'format' => 'xlsx']))->assertOk();
        $this->assertStringStartsWith('%PDF', $this->get(route('bank.accounts.statement', [$acc, 'format' => 'pdf']))->getContent());
        $this->get(route('bank.accounts.statement', [$acc, 'from' => '2026-05-01', 'to' => '2026-04-01']))->assertSessionHasErrors('to');
    }
}
