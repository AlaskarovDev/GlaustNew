<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\Reminder;
use App\Services\Cbar\CurrencyRates;
use App\Support\DealObligations;
use App\Support\Finance\BankFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Logistika: the act valued at CBAR in AZN/RUB/EUR, paid later (reminder) or split RUB + EUR with fees on top. */
class LogisticsActTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    private function world(): array
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        [$carrier, $rub, $eur] = $this->inTenant($admin, fn () => [
            Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Trans Logistik OOO', 'country' => 'Russia']),
            BankAccount::create(['name' => 'RUB', 'bank_name' => 'Kapital Bank', 'currency' => 'RUB', 'opening_balance' => 2000000, 'is_active' => true]),
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 20000, 'is_active' => true]),
        ]);

        return [$admin, $inv, $deal, $carrier, $rub, $eur];
    }

    public function test_fee_rule_for_rubles_uses_the_eur_limits_at_cbar(): void
    {
        $eurAzn = 1.9129;
        $rubAzn = 0.020186;
        $min = round(25 * $eurAzn / $rubAzn, 2);
        $max = round(300 * $eurAzn / $rubAzn, 2);
        $this->assertSame($min, BankFee::for('RUB', 100000, $rubAzn, $eurAzn)['amount'], 'small payment: 25 EUR in roubles');
        $this->assertSame(round(2000000 * 0.0025, 2), BankFee::for('RUB', 2000000, $rubAzn, $eurAzn)['amount'], '0.25 %');
        $this->assertSame($max, BankFee::for('RUB', 50000000, $rubAzn, $eurAzn)['amount'], 'capped: 300 EUR in roubles');
        $this->assertSame(25.0, BankFee::for('EUR', 1000, $eurAzn, $eurAzn)['amount']);
    }

    public function test_act_planned_later_with_a_reminder(): void
    {
        [$admin, $inv, $deal, $carrier] = $this->world();
        $this->actingAs($admin);
        $this->get(route('deals.show', [$deal, 'tab' => 'logistics']))->assertOk()
            ->assertSee('Fakturada qeyd olunan logistika ödənişi')->assertSee(money(10200, 'EUR'))->assertSee('Logistika aktı əlavə et');

        $day = today()->subDays(2)->toDateString();
        $this->post(route('deals.logistics-acts.store', $deal), [
            'counterparty_id' => $carrier->id, 'invoice_id' => $inv->id, 'act_number' => 'LA-17', 'act_date' => $day, 'amount' => '10 200', 'currency' => 'EUR',
            'logistics_invoice_number' => 'INV-55', 'logistics_invoice_date' => $day,
            'payment_plan' => 'later', 'planned_date' => today()->addDays(3)->toDateString(), 'remind' => '1',
        ])->assertSessionHasNoErrors();

        $act = $this->inTenant($admin, fn () => LogisticsAct::firstOrFail());
        $rates = app(CurrencyRates::class);
        $this->assertSame(round(10200 * $rates->rate('EUR', $day), 2), (float) $act->amount_azn);
        $this->assertSame(round(10200 * $rates->rate('EUR', $day) / $rates->rate('RUB', $day), 2), (float) $act->amount_rub, 'act in roubles at CBAR');
        $this->assertSame(10200.0, (float) $act->amount_eur);
        $reminder = $this->inTenant($admin, fn () => Reminder::findOrFail($act->reminder_id));
        $this->assertSame(today()->addDays(3)->setTime(9, 0)->toDateTimeString(), $reminder->remind_at->toDateTimeString());
        $this->assertSame('logistics_payment', $reminder->source);

        // The obligation now comes from the act, with the carrier's name.
        $ob = $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)));
        $this->assertSame(['EUR' => 10200.0], $ob['logistics']['due']);
        $this->assertSame('Trans Logistik OOO', $ob['logistics']['company']);
        $this->get(route('deals.show', [$deal, 'tab' => 'logistics']))->assertOk()->assertSee('LA-17')->assertSee('INV-55')->assertSee('xatırlatma')->assertSee('Ödəniş et');
    }

    public function test_act_paid_today_half_in_rubles_half_in_euro(): void
    {
        [$admin, $inv, $deal, $carrier, $rub, $eur] = $this->world();
        $this->actingAs($admin);
        $rates = app(CurrencyRates::class);
        $today = today()->toDateString();

        $this->post(route('deals.logistics-acts.store', $deal), [
            'counterparty_id' => $carrier->id, 'act_number' => 'LA-18', 'act_date' => $today, 'amount' => '10200', 'currency' => 'EUR', 'payment_plan' => 'today',
            'parts' => [
                ['act_amount' => '5100', 'currency' => 'RUB', 'bank_account_id' => $rub->id, 'bank_rate' => '92,5'],
                ['act_amount' => '5100', 'currency' => 'EUR', 'bank_account_id' => $eur->id],
            ],
        ])->assertSessionHasNoErrors();

        $act = $this->inTenant($admin, fn () => LogisticsAct::with('payments')->firstOrFail());
        $this->assertSame('paid', $act->status());
        [$pr, $pe] = $act->payments->all();

        // RUB part: 5 100 EUR × 92.5 = 471 750 RUB; 0.25 % of it is ~12.75 EUR, under the minimum,
        // so the fee is 25 EUR in roubles at CBAR.
        $this->assertSame(471750.0, (float) $pr->amount);
        $this->assertSame(round(5100 * $rates->rate('EUR', $today) / $rates->rate('RUB', $today), 2), (float) $pr->amount_cbar);
        $this->assertSame(round(25 * $rates->rate('EUR', $today) / $rates->rate('RUB', $today), 2), (float) $pr->fee_amount);
        $this->assertSame(25.0, (float) $pr->fee_eur, 'fee shown in EUR');
        $this->assertSame(round((float) $pr->fee_amount * $rates->rate('RUB', $today), 2), (float) $pr->fee_azn, 'fee shown in AZN');
        $this->assertSame(round(2000000 - 471750 - (float) $pr->fee_amount, 2), $this->inTenant($admin, fn () => BankAccount::find($rub->id)->balance()), 'fee added on top');

        // EUR part: no conversion; 0.25 % of 5 100 = 12.75 -> the 25 EUR minimum.
        $this->assertSame(5100.0, (float) $pe->amount);
        $this->assertSame(25.0, (float) $pe->fee_amount);
        $this->assertSame(round(20000 - 5100 - 25, 2), $this->inTenant($admin, fn () => BankAccount::find($eur->id)->balance()));

        $this->assertSame(2, $this->inTenant($admin, fn () => Expense::whereHas('category', fn ($q) => $q->where('name', 'Bank komissiyası'))->count()));
        $this->assertSame($carrier->id, $this->inTenant($admin, fn () => BankTransaction::find($pr->transaction_id))->counterparty_id);
        $this->assertSame([], $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)))['logistics']['due'] ?: [], 'act settled');

        // Cancelling a part reopens it.
        $this->delete(route('deals.logistics-payments.destroy', [$deal, $pe]))->assertRedirect();
        $this->assertSame(5100.0, $this->inTenant($admin, fn () => LogisticsAct::with('payments')->find($act->id))->remaining());
        $this->assertSame(20000.0, $this->inTenant($admin, fn () => BankAccount::find($eur->id)->balance()));
    }

    public function test_payment_rules_are_enforced(): void
    {
        [$admin, $inv, $deal, $carrier, $rub, $eur] = $this->world();
        $this->actingAs($admin);
        $this->post(route('deals.logistics-acts.store', $deal), ['act_number' => 'LA-19', 'act_date' => today()->toDateString(), 'amount' => '1000', 'currency' => 'EUR',
            'payment_plan' => 'later', 'planned_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $act = $this->inTenant($admin, fn () => LogisticsAct::firstOrFail());
        $pay = fn (array $parts) => $this->post(route('deals.logistics-acts.pay', [$deal, $act]), ['payment_date' => today()->toDateString(), 'parts' => $parts]);

        $pay([['act_amount' => '1500', 'currency' => 'EUR', 'bank_account_id' => $eur->id]])->assertSessionHasErrors('parts');
        $pay([['act_amount' => '500', 'currency' => 'RUB', 'bank_account_id' => $eur->id, 'bank_rate' => '90']])->assertSessionHasErrors('parts.0.bank_account_id');
        $pay([['act_amount' => '500', 'currency' => 'RUB', 'bank_account_id' => $rub->id]])->assertSessionHasErrors('parts.0.bank_rate');
        $this->assertSame(0, $this->inTenant($admin, fn () => LogisticsPayment::count()));

        // Partial payment, then the rest later from the other account.
        $pay([['act_amount' => '400', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'fee_amount' => '0']])->assertSessionHasNoErrors();
        $this->assertSame('partial', $this->inTenant($admin, fn () => LogisticsAct::with('payments')->find($act->id))->status());
        $pay([['act_amount' => '600', 'currency' => 'RUB', 'bank_account_id' => $rub->id, 'bank_rate' => '91']])->assertSessionHasNoErrors();
        $this->assertSame('paid', $this->inTenant($admin, fn () => LogisticsAct::with('payments')->find($act->id))->status());

        $this->delete(route('deals.logistics-acts.destroy', [$deal, $act]))->assertRedirect();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()));
        $this->assertSame(0, $this->inTenant($admin, fn () => Expense::count()));
    }
}
