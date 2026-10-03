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
