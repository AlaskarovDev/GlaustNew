<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\SupplierPayment;
use App\Services\Cbar\CurrencyRates;
use App\Support\DealObligations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Paying the seller: CBAR vs the bank's rate, the bank fee (EUR 0.25 %, 25–300) debited together and booked. */
class SupplierPaymentTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_fee_rule(): void
    {
        $this->assertSame(25.0, SupplierPayment::feeFor('EUR', 5000)['amount'], 'below the minimum');
        $this->assertSame(125.0, SupplierPayment::feeFor('EUR', 50000)['amount'], '0.25 %');
        $this->assertSame(300.0, SupplierPayment::feeFor('EUR', 191922)['amount'], 'capped at 300');
        $this->assertNull(SupplierPayment::feeFor('USD', 1000), 'no rule = no automatic fee');
    }

    /** The fee can come off another account: converted from the payment currency at CBAR of the payment date. */
    public function test_fee_from_another_account_at_cbar(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        [$eurAcc, $rubAcc] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'PAŞA Bank', 'currency' => 'EUR', 'opening_balance' => 200000, 'is_active' => true]),
            BankAccount::create(['name' => 'RUB', 'bank_name' => 'TuranBank', 'currency' => 'RUB', 'opening_balance' => 1000000, 'is_active' => true]),
        ]);
        $day = today()->subDays(2)->toDateString();
        $rates = app(CurrencyRates::class);
        $toRub = $rates->rate('EUR', $day) / $rates->rate('RUB', $day);

        $this->actingAs($admin)->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()->assertSee('Komissiya hansı hesabdan ödənilsin');
        // 70 575.57 EUR from the EUR account; the fee (0.25 % = 176.44 EUR) from the RUB account
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '70 575,57',
            'bank_account_id' => $eurAcc->id, 'fee_account_id' => $rubAcc->id])->assertSessionHasNoErrors();

        $p = $this->inTenant($admin, fn () => SupplierPayment::firstOrFail());
        $this->assertSame(176.44, (float) $p->fee_amount);
        $this->assertSame(round(176.44 * $toRub, 2), (float) $p->fee_account_amount, 'fee in roubles at CBAR');
        $this->assertSame('RUB', $p->fee_account_currency);
        $this->assertTrue($p->feeFromOtherAccount());
        $this->assertSame(70575.57, $p->totalDebit(), 'the EUR account pays only the payment');
        $this->assertSame(round(200000 - 70575.57, 2), $this->inTenant($admin, fn () => BankAccount::find($eurAcc->id)->balance()));
        $this->assertSame(round(1000000 - round(176.44 * $toRub, 2), 2), $this->inTenant($admin, fn () => BankAccount::find($rubAcc->id)->balance()));
        $fee = $this->inTenant($admin, fn () => Expense::findOrFail($p->fee_expense_id));
        $this->assertSame(['RUB', $rubAcc->id], [$fee->currency, (int) $fee->bank_account_id]);

        // no fee account given: from the payment account, as before
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '1000',
            'bank_account_id' => $eurAcc->id])->assertSessionHasNoErrors();
        $p2 = $this->inTenant($admin, fn () => SupplierPayment::latest('id')->first());
        $this->assertFalse($p2->feeFromOtherAccount());
        $this->assertSame(1025.0, $p2->totalDebit());
    }

    /** «Bank kursu ilə hesabla»: the fee off another account converted at the bank's rate typed for it; its difference to CBAR is kept. */
    public function test_fee_from_another_account_at_the_banks_rate(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        [$eurAcc, $aznAcc] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'Turan Bank', 'currency' => 'EUR', 'opening_balance' => 200000, 'is_active' => true]),
            BankAccount::create(['name' => 'AZN', 'bank_name' => 'Turan Bank', 'currency' => 'AZN', 'opening_balance' => 10000, 'is_active' => true]),
        ]);
        $day = today()->subDays(2)->toDateString();
        $eur = app(CurrencyRates::class)->rate('EUR', $day);

        $this->actingAs($admin)->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()->assertSee('Bank kursu ilə hesabla');
        // 73 644.80 EUR; fee 184.11 EUR off the AZN account at the bank's 1.95
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '73 644,80',
            'bank_account_id' => $eurAcc->id, 'fee_amount' => '184,11', 'fee_account_id' => $aznAcc->id, 'fee_bank_rate' => '1,95'])->assertSessionHasNoErrors();

        $p = $this->inTenant($admin, fn () => SupplierPayment::firstOrFail());
        $this->assertSame([359.01, 1.95], [(float) $p->fee_account_amount, (float) $p->fee_bank_rate], '184.11 × 1.95');
        $this->assertSame(round(359.01 - round(184.11 * $eur, 2), 2), (float) $p->fee_difference_azn, 'against CBAR');
        $this->assertSame(round(10000 - 359.01, 2), $this->inTenant($admin, fn () => BankAccount::find($aznAcc->id)->balance()));
        $this->assertSame(359.01, (float) $this->inTenant($admin, fn () => Expense::findOrFail($p->fee_expense_id)->amount));

        $fx = $this->inTenant($admin, fn () => \App\Support\FxResults::for(collect([Deal::find($deal->id)])));
        $this->assertSame(-(float) $p->fee_difference_azn, collect($fx['rows'])->firstWhere('kind', 'Bank komissiyası')['azn']);
    }

    public function test_paying_from_an_azn_account_at_the_banks_rate_with_fee(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        $azn = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'AZN', 'bank_name' => 'Kapital Bank', 'currency' => 'AZN', 'opening_balance' => 400000, 'is_active' => true]));
        $day = today()->subDays(2)->toDateString();
        $eur = app(CurrencyRates::class)->rate('EUR', $day);

        $this->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()->assertSee('Satıcıya ödəniş')->assertSee('Bank komissiyası');

        // 50 000 EUR from the AZN account at the bank's 1.9300; fee 0.25 % = 125 EUR.
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '50 000',
            'bank_account_id' => $azn->id, 'bank_rate' => '1,93', 'reference' => 'PT-9'])->assertSessionHasNoErrors();

        $p = $this->inTenant($admin, fn () => SupplierPayment::firstOrFail());
        $this->assertSame(96500.0, (float) $p->account_amount, '50 000 × 1.93');
        $this->assertSame(round(50000 * $eur, 2), (float) $p->account_amount_cbar);
        $this->assertSame(round(96500 - round(50000 * $eur, 2), 2), (float) $p->difference);
        $this->assertSame(125.0, (float) $p->fee_amount);
        $this->assertSame(241.25, (float) $p->fee_account_amount, '125 EUR × 1.93');
        $this->assertSame(96741.25, $p->totalDebit());
        $this->assertSame([0.25, 25.0, 300.0], [(float) $p->fee_percent, (float) $p->fee_minimum, (float) $p->fee_maximum]);

        // Payment and fee both leave the account; the fee is an expense in its own category.
        $this->assertSame(round(400000 - 96741.25, 2), $this->inTenant($admin, fn () => BankAccount::find($azn->id)->balance()));
        $fee = $this->inTenant($admin, fn () => Expense::with('category')->findOrFail($p->fee_expense_id));
        $this->assertSame('Bank komissiyası', $fee->category->name);
        $this->assertSame(241.25, (float) $fee->amount);
        $this->assertSame($deal->id, $fee->deal_id);

        // The seller's balance falls in EUR, whichever account paid.
        $ob = $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)));
        $this->assertSame(['EUR' => 141922.0], $ob['seller']['due']);
        $this->assertSame(['EUR' => 50000.0], $ob['seller']['goods']);

        $this->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()->assertSee('PT-9')->assertSee(money(96741.25, 'AZN'));

        // A different-currency account needs the bank's rate.
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '10', 'bank_account_id' => $azn->id])
            ->assertSessionHasErrors('bank_rate');

        // Cancelling removes the debit and the fee.
        $this->delete(route('deals.supplier-payments.destroy', [$deal, $p]))->assertRedirect();
        $this->assertSame(400000.0, $this->inTenant($admin, fn () => BankAccount::find($azn->id)->balance()));
        $this->assertSame(0, $this->inTenant($admin, fn () => Expense::count()));
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()));
    }

    public function test_same_currency_account_and_manual_fee(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        $eurAcc = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 250000, 'is_active' => true]));

        // Whole invoice from a EUR account: no conversion, fee capped at 300 EUR.
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => today()->toDateString(), 'currency' => 'EUR', 'amount' => '191922', 'bank_account_id' => $eurAcc->id])
            ->assertSessionHasNoErrors();
        $p = $this->inTenant($admin, fn () => SupplierPayment::firstOrFail());
        $this->assertSame(1.0, (float) $p->bank_rate);
        $this->assertSame(0.0, (float) $p->difference);
        $this->assertSame(300.0, (float) $p->fee_amount);
        $this->assertSame(round(250000 - 191922 - 300, 2), $this->inTenant($admin, fn () => BankAccount::find($eurAcc->id)->balance()));

        // A fee typed by hand wins over the rule (0 = no fee, no expense).
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => today()->toDateString(), 'currency' => 'EUR', 'amount' => '100', 'bank_account_id' => $eurAcc->id, 'fee_amount' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inTenant($admin, fn () => Expense::count()));
    }
}
