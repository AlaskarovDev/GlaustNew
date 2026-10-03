<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CurrencyExchange;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Valyuta alış-satışı: CBAR vs bank rate stored, money moved as a conversion pair. */
class CurrencyExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_buy_and_sell_keep_cbar_and_bank_figures(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$azn, $usd, $eur] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'AZN', 'bank_name' => 'Kapital Bank', 'currency' => 'AZN', 'opening_balance' => 50000, 'is_active' => true]),
            BankAccount::create(['name' => 'USD', 'bank_name' => 'Kapital Bank', 'currency' => 'USD', 'is_active' => true]),
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 5000, 'is_active' => true]),
        ]);
        $this->actingAs($admin);
        $day = today()->subDays(2)->toDateString();
        $usdRate = app(CurrencyRates::class)->rate('USD', $day);

        $this->get(route('bank.exchanges.index'))->assertOk()->assertSee('Yeni əməliyyat');

        // Buy 10 000 USD paying AZN at the bank's 1.7050.
        $this->post(route('bank.exchanges.store'), ['exchange_date' => $day, 'direction' => 'buy', 'currency' => 'USD', 'counter_currency' => 'AZN',
            'amount' => '10 000', 'bank_rate' => '1,7050', 'from_account_id' => $azn->id, 'to_account_id' => $usd->id, 'reference' => 'KB-1'])->assertSessionHasNoErrors();
        $x = $this->inTenant($admin, fn () => CurrencyExchange::firstOrFail());
        $cbar = round(10000 * $usdRate, 2);
        $this->assertSame(17050.0, (float) $x->counter_amount);
        $this->assertSame($cbar, (float) $x->counter_amount_cbar);
        $this->assertSame(round(17050 - $cbar, 2), (float) $x->difference, 'paid above CBAR = loss');
        $this->assertSame((float) $x->difference, (float) $x->difference_azn);
        $this->assertEqualsWithDelta($usdRate, (float) $x->cbar_cross, 1e-9);
        $this->assertSame(1.705, (float) $x->bank_rate);
        $this->assertSame(32950.0, $this->inTenant($admin, fn () => BankAccount::find($azn->id)->balance()));
        $this->assertSame(10000.0, $this->inTenant($admin, fn () => BankAccount::find($usd->id)->balance()));
        $legs = $this->inTenant($admin, fn () => BankTransaction::whereIn('id', [$x->out_transaction_id, $x->in_transaction_id])->get());
        $this->assertSame(['conversion'], $legs->pluck('kind')->unique()->values()->all());
        $this->assertSame(1, $legs->pluck('transfer_group')->unique()->count());

        // Sell 1 000 EUR for AZN at the bank's 1.95: the difference is CBAR minus the bank.
        $eurRate = app(CurrencyRates::class)->rate('EUR', $day);
        $this->post(route('bank.exchanges.store'), ['exchange_date' => $day, 'direction' => 'sell', 'currency' => 'EUR', 'counter_currency' => 'AZN',
            'amount' => '1000', 'bank_rate' => '1.95', 'from_account_id' => $eur->id, 'to_account_id' => $azn->id])->assertSessionHasNoErrors();
        $s = $this->inTenant($admin, fn () => CurrencyExchange::where('direction', 'sell')->firstOrFail());
        $this->assertSame(round(round(1000 * $eurRate, 2) - 1950, 2), (float) $s->difference);
        $this->assertSame(4000.0, $this->inTenant($admin, fn () => BankAccount::find($eur->id)->balance()));

        // Accounts must match the currencies; same currency twice is refused.
        $this->post(route('bank.exchanges.store'), ['exchange_date' => $day, 'direction' => 'buy', 'currency' => 'USD', 'counter_currency' => 'AZN',
            'amount' => '1', 'bank_rate' => '1.7', 'from_account_id' => $eur->id, 'to_account_id' => $usd->id])->assertSessionHasErrors('from_account_id');
        $this->post(route('bank.exchanges.store'), ['exchange_date' => $day, 'direction' => 'buy', 'currency' => 'USD', 'counter_currency' => 'USD',
            'amount' => '1', 'bank_rate' => '1', 'from_account_id' => $usd->id, 'to_account_id' => $azn->id])->assertSessionHasErrors('counter_currency');
        $this->post(route('bank.exchanges.store'), ['exchange_date' => today()->addDay()->toDateString(), 'direction' => 'buy', 'currency' => 'USD', 'counter_currency' => 'AZN',
            'amount' => '1', 'bank_rate' => '1.7', 'from_account_id' => $azn->id, 'to_account_id' => $usd->id])->assertSessionHasErrors('exchange_date');

        $this->get(route('bank.exchanges.index'))->assertOk()->assertSee('KB-1', false)->assertSee(money(17050, 'AZN'))->assertSee('Satış');

        // Cancelling removes both legs.
        $this->delete(route('bank.exchanges.destroy', $x))->assertRedirect();
        $this->assertSame(50000.0 + 1950, $this->inTenant($admin, fn () => BankAccount::find($azn->id)->balance()));
        $this->assertSame(0.0, $this->inTenant($admin, fn () => BankAccount::find($usd->id)->balance()));
    }
}
