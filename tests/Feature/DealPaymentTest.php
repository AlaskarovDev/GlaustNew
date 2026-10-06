<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Project;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mədaxillər: the buyer's payment, valued at CBAR and at the bank's own rate. */
class DealPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$deal, $rub, $eur] = $this->inTenant($admin, function () {
            $buyer = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'CCL Kontur LLC', 'country' => 'Russia']);
            $p = Project::create(['code' => 'P', 'name' => 'P', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);
            $deal = Deal::create(['project_id' => $p->id, 'code' => 'TD-1', 'title' => 'T', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'active', 'counterparty_id' => $buyer->id]);

            return [$deal,
                BankAccount::create(['name' => 'RUB hesab', 'bank_name' => 'Kapital Bank', 'currency' => 'RUB', 'is_active' => true]),
                BankAccount::create(['name' => 'EUR hesab', 'bank_name' => 'Kapital Bank', 'currency' => 'EUR', 'is_active' => true])];
        });

        return [$admin, $deal, $rub, $eur];
    }

    /** Incoming money is valued at CBAR of its date only — there is no bank rate on the form, a posted one is ignored. */
    public function test_payment_is_recorded_at_cbar_of_its_date(): void
    {
        [$admin, $deal, $rub] = $this->world();
        $day = today()->subDays(2)->toDateString();
        $this->actingAs($admin)->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()
            ->assertSee('Yeni mədaxil')->assertSee('CCL Kontur LLC')->assertSee('AZN ekvivalenti (CBAR kursu ilə)');

        $this->post(route('deals.payments.store', $deal), [
            'transaction_date' => $day, 'currency' => 'RUB', 'amount' => '19 800 178,00', 'bank_account_id' => $rub->id, 'applied_rate' => '0,0205', 'reference' => 'PP-77',
        ])->assertSessionHasNoErrors()->assertRedirect(route('deals.show', [$deal, 'tab' => 'income']));

        $tx = $this->inTenant($admin, fn () => BankTransaction::firstOrFail());
        $cbar = app(CurrencyRates::class)->rate('RUB', $day);
        $this->assertSame('in', $tx->direction);
        $this->assertSame($deal->id, $tx->deal_id);
        $this->assertSame($deal->counterparty_id, $tx->counterparty_id, 'payer = the buyer, automatically');
        $this->assertSame(19800178.0, (float) $tx->amount);
        $this->assertEqualsWithDelta($cbar, (float) $tx->cbar_rate, 1e-8);
        $this->assertEqualsWithDelta($cbar, (float) $tx->applied_rate, 1e-8, 'no bank rate for incoming money');
        $this->assertSame(round(19800178 * $cbar, 2), (float) $tx->amount_azn);
        $this->assertSame(round(19800178 * $cbar, 2), (float) $tx->cbar_amount_azn);

        $this->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()->assertSee('PP-77')->assertSee(money(19800178, 'RUB'));
        $this->get(route('bank.transactions.index'))->assertOk()->assertSee('CCL Kontur LLC');

        $this->delete(route('deals.payments.destroy', [$deal, $tx]))->assertRedirect();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()));
    }

    public function test_without_bank_rate_the_cbar_rate_is_used_and_bad_input_is_refused(): void
    {
        [$admin, $deal, $rub, $eur] = $this->world();
        $this->actingAs($admin);
        $day = today()->subDay()->toDateString();

        $this->post(route('deals.payments.store', $deal), ['transaction_date' => $day, 'currency' => 'RUB', 'amount' => '1000', 'bank_account_id' => $rub->id])->assertSessionHasNoErrors();
        $tx = $this->inTenant($admin, fn () => BankTransaction::firstOrFail());
        $this->assertSame((float) $tx->cbar_rate, (float) $tx->applied_rate);

        // Currency of the payment and of the account must match.
        $this->post(route('deals.payments.store', $deal), ['transaction_date' => $day, 'currency' => 'RUB', 'amount' => '1000', 'bank_account_id' => $eur->id])
            ->assertSessionHasErrors('bank_account_id');
        // No future dates (no CBAR rate yet), no zero amounts.
        $this->post(route('deals.payments.store', $deal), ['transaction_date' => today()->addDay()->toDateString(), 'currency' => 'RUB', 'amount' => '1000', 'bank_account_id' => $rub->id])
            ->assertSessionHasErrors('transaction_date');
        $this->post(route('deals.payments.store', $deal), ['transaction_date' => $day, 'currency' => 'RUB', 'amount' => '0', 'bank_account_id' => $rub->id])
            ->assertSessionHasErrors('amount');
        $this->assertSame(1, $this->inTenant($admin, fn () => BankTransaction::count()));

        foreach (['invoices', 'income', 'logistics'] as $tab) {
            $this->get(route('deals.show', [$deal, 'tab' => $tab]))->assertOk();
        }
    }
}
