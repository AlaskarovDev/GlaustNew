<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Support\DealFinance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Maliyyə icmalı: currency bought for a Trade against what it owes — still to buy, or bought too much. */
class DealFinanceTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_currency_bought_for_a_trade_against_its_obligations(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);   // proforma ready: logistics 10 200 EUR is now owed
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        [$azn, $eur, $carrier] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'AZN', 'bank_name' => 'Kapital Bank', 'currency' => 'AZN', 'opening_balance' => 1000000, 'is_active' => true]),
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 0, 'is_active' => true]),
            Counterparty::create(['type' => 'logistics', 'entity_type' => 'legal', 'name' => 'Ritloga', 'country' => 'Poland']),
        ]);
        $day = today()->subDay()->toDateString();
        $this->actingAs($admin);
        // the seller is paid from the AZN account, fee included there
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => (string) $inv->total,
            'bank_account_id' => $azn->id, 'bank_rate' => '2'])->assertSessionHasNoErrors();
        $eurRow = fn () => $this->inTenant($admin, fn () => DealFinance::currencies(Deal::find($deal->id))['EUR']);
        $this->assertSame(10200.0, $eurRow()['due'], 'only logistics is left to pay in EUR');

        // 3 000 EUR bought for the Trade: 7 200 still to buy
        $buy = fn ($amount) => $this->post(route('bank.exchanges.store'), ['exchange_date' => $day, 'direction' => 'buy', 'currency' => 'EUR', 'counter_currency' => 'AZN',
            'amount' => $amount, 'bank_rate' => '2,02', 'from_account_id' => $azn->id, 'to_account_id' => $eur->id, 'deal_id' => $deal->id])->assertSessionHasNoErrors();
        $buy('3000');
        $this->assertSame([3000.0, 7200.0, 0.0], [$eurRow()['acquired'], $eurRow()['to_buy'], $eurRow()['surplus']]);
        $this->get(route('ajax.deal-fx', $deal))->assertJsonPath('rows.0.currency', 'EUR')->assertJsonPath('rows.0.to_buy', 7200);

        // 7 500 more bought, logistics invoiced and paid 10 200 without a fee: 300 EUR bought too much
        $buy('7500');
        $this->post(route('deals.logistics-acts.store', $deal), ['counterparty_id' => $carrier->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'L-1',
            'logistics_invoice_date' => $day, 'amount' => '10200', 'currency' => 'EUR', 'payment_plan' => 'invoice',
            'parts' => [['act_amount' => '10200', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => $day, 'fee_amount' => '0']]])->assertSessionHasNoErrors();
        $r = $eurRow();
        $this->assertSame([0.0, 10500.0, 10200.0, 0.0, 300.0], [$r['due'], $r['acquired'], $r['spent'], $r['to_buy'], $r['surplus']]);

        $this->get(route('deals.show', [$deal, 'tab' => 'finance']))->assertOk()->assertSee('Maliyyə icmalı')->assertSee('Artıq alınıb')
            ->assertSee(money(300, 'EUR'))->assertSee('Valyuta alışı')->assertSee('Logistika ödənişi')->assertSee('Satıcıya ödəniş');
        $this->get(route('bank.exchanges.index'))->assertOk()->assertSee('Trade seçsəniz, onun valyuta ehtiyacı burada görünəcək.');
    }
}
