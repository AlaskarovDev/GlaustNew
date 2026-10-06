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
            ->assertSee('Fakturada qeyd olunan logistika ödənişi')->assertSee(money(10200, 'EUR'))->assertSee('Logistika invoysu əlavə et');

        $day = today()->subDays(2)->toDateString();
        $this->post(route('deals.logistics-acts.store', $deal), [
            'counterparty_id' => $carrier->id, 'invoice_id' => $inv->id, 'has_act' => '1', 'act_number' => 'LA-17', 'act_date' => $day, 'amount' => '10 200', 'currency' => 'EUR',
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
            'counterparty_id' => $carrier->id, 'logistics_invoice_number' => 'LA-18', 'logistics_invoice_date' => $today, 'amount' => '10200', 'currency' => 'EUR', 'payment_plan' => 'today',
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
        $this->post(route('deals.logistics-acts.store', $deal), ['logistics_invoice_number' => 'LA-19', 'logistics_invoice_date' => today()->toDateString(), 'amount' => '1000', 'currency' => 'EUR',
            'payment_plan' => 'later', 'planned_date' => today()->addDay()->toDateString()])->assertSessionHasNoErrors();
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

    /** Split terms: each part on its own date, valued at that day's CBAR and booked on that day. */
    public function test_split_parts_on_different_dates(): void
    {
        [$admin, $inv, $deal, $carrier, $rub, $eur] = $this->world();
        $this->actingAs($admin);
        $d1 = today()->subDays(5)->toDateString();
        $d2 = today()->subDay()->toDateString();
        $this->post(route('deals.logistics-acts.store', $deal), [
            'counterparty_id' => $carrier->id, 'logistics_invoice_number' => 'LA-20', 'logistics_invoice_date' => $d1, 'amount' => '10200', 'currency' => 'EUR', 'payment_plan' => 'today',
            'parts' => [
                ['act_amount' => '6000', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => $d1],
                ['act_amount' => '4200', 'currency' => 'RUB', 'bank_account_id' => $rub->id, 'bank_rate' => '93', 'payment_date' => $d2],
            ],
        ])->assertSessionHasNoErrors();

        $act = $this->inTenant($admin, fn () => LogisticsAct::with('payments')->firstOrFail());
        [$p1, $p2] = $act->payments->all();
        $this->assertSame([$d1, $d2], [$p1->payment_date->toDateString(), $p2->payment_date->toDateString()]);
        $rates = app(CurrencyRates::class);
        $this->assertEqualsWithDelta($rates->rate('EUR', $d2) / $rates->rate('RUB', $d2), (float) $p2->cbar_cross, 1e-9, 'CBAR of the part date');
        $this->assertSame($d2, $this->inTenant($admin, fn () => BankTransaction::find($p2->transaction_id))->transaction_date->toDateString(), 'booked on the part date');
        $this->get(route('deals.show', [$deal, 'tab' => 'logistics']))->assertOk()->assertSee(azdate($d1))->assertSee(azdate($d2));

        // No future dates for a part.
        $this->post(route('deals.logistics-acts.store', $deal), ['logistics_invoice_number' => 'LA-21', 'logistics_invoice_date' => $d1, 'amount' => '100', 'currency' => 'EUR', 'payment_plan' => 'today',
            'parts' => [['act_amount' => '100', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => today()->addDay()->toDateString()]]])
            ->assertSessionHasErrors('parts.0.payment_date');
    }

    /** The logistics invoice is the main document; the act (number, date, scan) is optional and can come later. */
    public function test_invoice_first_act_optional_and_payment_date_choice(): void
    {
        [$admin, $inv, $deal, $carrier, $rub, $eur] = $this->world();
        $this->actingAs($admin);
        $invDay = today()->subDays(4)->toDateString();
        $other = today()->subDay()->toDateString();

        // paid on another (past) date: the payment is made right away on that date
        $this->post(route('deals.logistics-acts.store', $deal), [
            'logistics_invoice_number' => 'LI-1', 'logistics_invoice_date' => $invDay, 'amount' => '1000', 'currency' => 'EUR',
            'payment_plan' => 'later', 'planned_date' => $other,
            'parts' => [['act_amount' => '1000', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => $other]],
        ])->assertSessionHasNoErrors();
        $li = $this->inTenant($admin, fn () => LogisticsAct::with('payments')->where('logistics_invoice_number', 'LI-1')->firstOrFail());
        $this->assertNull($li->act_number);
        $this->assertSame($invDay, $li->docDate()->toDateString(), 'valued at the invoice date');
        $this->assertSame(round(1000 * app(CurrencyRates::class)->rate('EUR', $invDay), 2), (float) $li->amount_azn);
        $this->assertSame('paid', $li->status());
        $this->assertSame($other, $li->payments->first()->payment_date->toDateString());

        // the act arrives later, with its scan
        $this->patch(route('deals.logistics-acts.act', [$deal, $li]), [
            'act_number' => 'AKT-9', 'act_date' => $other, 'act_file' => \Illuminate\Http\UploadedFile::fake()->create('akt.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $li = $this->inTenant($admin, fn () => LogisticsAct::with('attachments')->find($li->id));
        $this->assertSame('AKT-9', $li->act_number);
        $this->assertCount(1, $li->attachments);
        $this->get(route('deals.show', [$deal, 'tab' => 'logistics']))->assertOk()->assertSee('LI-1')->assertSee('AKT-9')->assertSee('akt.pdf');

        // the act fields are required only when the act is ticked
        $this->post(route('deals.logistics-acts.store', $deal), [
            'logistics_invoice_number' => 'LI-2', 'logistics_invoice_date' => $invDay, 'amount' => '500', 'currency' => 'EUR', 'has_act' => '1',
            'payment_plan' => 'later', 'planned_date' => today()->addDays(5)->toDateString(), 'remind' => '1',
        ])->assertSessionHasErrors(['act_number', 'act_date']);
        // a future date keeps it unpaid with a reminder
        $this->post(route('deals.logistics-acts.store', $deal), [
            'logistics_invoice_number' => 'LI-2', 'logistics_invoice_date' => $invDay, 'amount' => '500', 'currency' => 'EUR', 'has_act' => '0',
            'payment_plan' => 'later', 'planned_date' => today()->addDays(5)->toDateString(), 'remind' => '1',
        ])->assertSessionHasNoErrors();
        $li2 = $this->inTenant($admin, fn () => LogisticsAct::with('payments')->where('logistics_invoice_number', 'LI-2')->firstOrFail());
        $this->assertSame('unpaid', $li2->status());
        $this->assertNotNull($li2->reminder_id);
        // the invoice number and date are required
        $this->post(route('deals.logistics-acts.store', $deal), ['amount' => '5', 'currency' => 'EUR', 'payment_plan' => 'invoice'])
            ->assertSessionHasErrors(['logistics_invoice_number', 'logistics_invoice_date']);
    }

    /** Two logistics companies share the planned logistics: what the first does not cover stays open. */
    public function test_logistics_split_between_two_companies(): void
    {
        [$admin, $inv, $deal, $carrier, $rub, $eur] = $this->world();
        $second = $this->inTenant($admin, fn () => Counterparty::create(['type' => 'logistics', 'entity_type' => 'legal', 'name' => 'Ritloga', 'country' => 'Lithuania']));
        $this->actingAs($admin);
        $this->applyRub($inv);   // the buyer's proforma exists: the logistics obligation is open
        $day = today()->subDay()->toDateString();
        $pay = fn ($amount) => [['act_amount' => $amount, 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => $day, 'fee_amount' => '0']];

        // planned 10 200 EUR; the first company invoices and gets paid 4 000
        $this->post(route('deals.logistics-acts.store', $deal), ['counterparty_id' => $second->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'R-1',
            'logistics_invoice_date' => $day, 'amount' => '4000', 'currency' => 'EUR', 'payment_plan' => 'invoice', 'parts' => $pay('4000')])->assertSessionHasNoErrors();
        $ob = $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)));
        $this->assertSame(['EUR' => 6200.0], $ob['logistics']['due'], 'the rest stays open');
        $this->get(route('deals.show', [$deal, 'tab' => 'logistics']))->assertOk()->assertSee('Qalır')->assertSee(money(6200, 'EUR'));

        // the second company carries the rest
        $this->post(route('deals.logistics-acts.store', $deal), ['counterparty_id' => $carrier->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'T-9',
            'logistics_invoice_date' => $day, 'amount' => '6200', 'currency' => 'EUR', 'payment_plan' => 'invoice', 'parts' => $pay('6200')])->assertSessionHasNoErrors();
        $ob = $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)));
        $this->assertSame([], $ob['logistics']['due']);
        $this->assertSame(['EUR' => 10200.0], $ob['logistics']['paid']);
        $this->assertStringContainsString('Ritloga', $ob['logistics']['company']);
        $this->assertStringContainsString('Trans Logistik OOO', $ob['logistics']['company']);
        $this->get(route('deals.show', [$deal, 'tab' => 'logistics']))->assertOk()->assertSee('Tam invoys edilib')->assertSee('2 şirkət');
        $this->get(route('deals.show', $deal))->assertOk()->assertSee('tam ödənilib')->assertDontSee('Alıcı üçün proforma hazır olanda logistika');
    }

    /** A part entered as an amount in its own currency settles amount / bank rate of the act. */
    public function test_part_as_amount_in_payment_currency(): void
    {
        [$admin, $inv, $deal, $carrier, $rub, $eur] = $this->world();
        $azn = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'AZN', 'bank_name' => 'Kapital Bank', 'currency' => 'AZN', 'opening_balance' => 50000, 'is_active' => true]));
        $this->actingAs($admin);
        $day = today()->subDay()->toDateString();
        $this->post(route('deals.logistics-acts.store', $deal), [
            'logistics_invoice_number' => 'LA-30', 'logistics_invoice_date' => $day, 'amount' => '10200', 'currency' => 'EUR', 'payment_plan' => 'today',
            'parts' => [['amount' => '5 000', 'currency' => 'AZN', 'bank_account_id' => $azn->id, 'bank_rate' => '1,9129', 'payment_date' => $day]],
        ])->assertSessionHasNoErrors();

        $act = $this->inTenant($admin, fn () => LogisticsAct::with('payments')->firstOrFail());
        $p = $act->payments->first();
        $this->assertSame(5000.0, (float) $p->amount, 'exactly what was typed leaves the account');
        $this->assertSame(round(5000 / 1.9129, 2), (float) $p->act_amount);
        $this->assertSame(round(10200 - round(5000 / 1.9129, 2), 2), $act->remaining(), 'remaining debt in the act currency');
        $this->assertSame('partial', $act->status());
        $this->assertSame(round(50000 - 5000 - (float) $p->fee_amount, 2), $this->inTenant($admin, fn () => BankAccount::find($azn->id)->balance()));

        // Without the bank rate an amount in another currency is refused.
        $this->post(route('deals.logistics-acts.pay', [$deal, $act]), ['payment_date' => $day,
            'parts' => [['amount' => '100', 'currency' => 'AZN', 'bank_account_id' => $azn->id, 'payment_date' => $day]]])
            ->assertSessionHasErrors('parts.0.bank_rate');
    }
}
