<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankFeeRule;
use App\Models\Deal;
use App\Models\SupplierPayment;
use App\Support\Finance\BankFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Tənzimləmələr → Bank komissiyası: rules per bank and currency, each from its date on; each company its own. */
class BankFeeRuleTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_rules_from_a_date_per_bank(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->actingAs($admin)->get(route('settings.bank-fees'))->assertOk()->assertSee('sistem qaydası')->assertSee('Bütün banklar');

        // every bank: 0,3 % 30–400 EUR from 01.12.2026; PAŞA Bank: 0,1 % 10–100 from 01.12.2026
        $this->post(route('settings.bank-fees.store'), ['currency' => 'EUR', 'valid_from' => '2026-12-01', 'percent' => '0,3', 'minimum' => '30', 'maximum' => '400'])->assertSessionHasNoErrors();
        $this->post(route('settings.bank-fees.store'), ['bank_name' => 'PAŞA Bank', 'currency' => 'EUR', 'valid_from' => '2026-12-01', 'percent' => '0.1', 'minimum' => '10', 'maximum' => '100'])->assertSessionHasNoErrors();
        // max below min is refused
        $this->post(route('settings.bank-fees.store'), ['currency' => 'EUR', 'valid_from' => '2026-12-05', 'percent' => '1', 'minimum' => '50', 'maximum' => '10'])->assertSessionHasErrors('maximum');

        $this->inTenant($admin, function () {
            $this->assertSame(125.0, SupplierPayment::feeFor('EUR', 50000, '2026-11-30')['amount'], 'before: the system rule');
            $this->assertSame(150.0, SupplierPayment::feeFor('EUR', 50000, '2026-12-01')['amount'], 'from 01.12: 0,3 %');
            $this->assertSame(400.0, SupplierPayment::feeFor('EUR', 500000, '2026-12-10')['amount'], 'capped at 400');
            $this->assertSame(50.0, SupplierPayment::feeFor('EUR', 50000, '2026-12-01', 'paşa bank ')['amount'], 'the bank\'s own rule');
            $this->assertSame(125.0, SupplierPayment::feeFor('EUR', 50000, '2026-11-30', 'PAŞA Bank')['amount'], 'the bank\'s rule not yet in force');
            // RUB has no rule: the EUR rule of the bank, its limits converted (1 EUR = 2 AZN, 1 RUB = 0.02 AZN -> ×100)
            $this->assertSame(1000.0, BankFee::for('RUB', 1000, 0.02, 2.0, '2026-12-01', 'PAŞA Bank')['amount'], 'min 10 EUR = 1 000 RUB');
        });

        // the same bank, currency and date again replaces it
        $this->post(route('settings.bank-fees.store'), ['currency' => 'EUR', 'valid_from' => '2026-12-01', 'percent' => '0.2', 'minimum' => '20'])->assertSessionHasNoErrors();
        $this->inTenant($admin, function () {
            $this->assertSame(2, BankFeeRule::count());
            $this->assertSame(1000.0, SupplierPayment::feeFor('EUR', 500000, '2026-12-10')['amount'], 'no maximum now');
        });
        $this->get(route('settings.bank-fees'))->assertOk()->assertSee('PAŞA Bank')->assertSee('gələcək');

        // deleting brings the earlier rule back
        $rule = $this->inTenant($admin, fn () => BankFeeRule::whereNull('bank_name')->first());
        $this->delete(route('settings.bank-fees.destroy', $rule))->assertSessionHasNoErrors();
        $this->inTenant($admin, fn () => $this->assertSame(125.0, SupplierPayment::feeFor('EUR', 50000, '2026-12-10')['amount']));
    }

    public function test_a_payment_takes_the_rule_of_its_bank_and_date(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        $day = today()->subDays(2)->toDateString();
        [$pasha, $turan] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'PAŞA Bank', 'currency' => 'EUR', 'opening_balance' => 200000, 'is_active' => true]),
            BankAccount::create(['name' => 'EUR 2', 'bank_name' => 'Turan Bank', 'currency' => 'EUR', 'opening_balance' => 200000, 'is_active' => true]),
        ]);
        $this->actingAs($admin)->post(route('settings.bank-fees.store'), ['bank_name' => 'PAŞA Bank', 'currency' => 'EUR', 'valid_from' => $day, 'percent' => '0.1', 'minimum' => '10', 'maximum' => '100']);

        $this->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '50000', 'bank_account_id' => $pasha->id])->assertSessionHasNoErrors();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '10000', 'bank_account_id' => $turan->id])->assertSessionHasNoErrors();
        $fees = $this->inTenant($admin, fn () => SupplierPayment::orderBy('id')->pluck('fee_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([50.0, 25.0], $fees, 'PAŞA: 0,1 %; Turan: the rule for every bank');
    }
}
