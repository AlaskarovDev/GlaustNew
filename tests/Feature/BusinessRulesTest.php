<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Counterparty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private function party(string $type, string $name): Counterparty
    {
        return Counterparty::create(['type' => $type, 'entity_type' => 'legal', 'name' => $name, 'country' => 'Azərbaycan']);
    }

    private function contract(array $over = []): array
    {
        return array_merge([
            'number' => '', 'contract_date' => CarbonImmutable::today()->subDays(5)->toDateString(), 'kind' => 'sale', 'subject' => 'Xidmət',
            'amount' => '10 000,00', 'currency' => 'USD', 'status' => 'active',
        ], $over);
    }

    public function test_contract_requires_an_existing_counterparty(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $this->actingAs($admin)->post(route('contracts.store'), $this->contract())->assertSessionHasErrors('counterparty_id');
        $this->actingAs($admin)->post(route('contracts.store'), $this->contract(['counterparty_id' => 999999]))->assertSessionHasErrors('counterparty_id');
        $this->assertSame(0, $this->inTenant($admin, fn () => Contract::count()));
    }

    public function test_contract_kind_must_match_counterparty_type(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$customer, $supplier] = $this->inTenant($admin, fn () => [$this->party('customer', 'Müştəri MMC'), $this->party('supplier', 'Təchizatçı MMC')]);
        $this->actingAs($admin);

        $this->post(route('contracts.store'), $this->contract(['counterparty_id' => $supplier->id, 'kind' => 'sale']))->assertSessionHasErrors('kind');
        $this->post(route('contracts.store'), $this->contract(['counterparty_id' => $customer->id, 'kind' => 'purchase']))->assertSessionHasErrors('kind');
        $this->post(route('contracts.store'), $this->contract(['counterparty_id' => $customer->id]))->assertSessionHasNoErrors();

        $c = $this->inTenant($admin, fn () => Contract::firstOrFail());
        $this->assertSame(10000.0, (float) $c->amount);
        $this->assertGreaterThan(0, (float) $c->cbar_rate);
        $this->assertEqualsWithDelta(round(10000 * (float) $c->cbar_rate, 2), (float) $c->amount_azn, 0.001);
        $this->assertMatchesRegularExpression('/^MQ-\d{4}-0001$/', $c->number);
    }

    public function test_contract_is_not_saved_without_an_official_rate(): void
    {
        $date = CarbonImmutable::today()->subDays(4);
        $this->fakeCbar([$date->format('d.m.Y')]);
        $admin = $this->makeCompany();
        $customer = $this->inTenant($admin, fn () => $this->party('customer', 'Müştəri MMC'));

        $this->actingAs($admin)->post(route('contracts.store'), $this->contract(['counterparty_id' => $customer->id, 'contract_date' => $date->toDateString()]))
            ->assertSessionHasErrors('currency');
        $this->assertSame(0, $this->inTenant($admin, fn () => Contract::count()));

        // AZN needs no rate.
        $this->post(route('contracts.store'), $this->contract(['counterparty_id' => $customer->id, 'contract_date' => $date->toDateString(), 'currency' => 'AZN']))->assertSessionHasNoErrors();
    }

    public function test_payment_schedule_cannot_exceed_contract_amount(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $customer = $this->inTenant($admin, fn () => $this->party('customer', 'Müştəri MMC'));
        $this->actingAs($admin)->post(route('contracts.store'), $this->contract([
            'counterparty_id' => $customer->id, 'currency' => 'AZN', 'amount' => '1000',
            'payments' => [['due_date' => today()->toDateString(), 'amount' => '600'], ['due_date' => today()->addMonth()->toDateString(), 'amount' => '500']],
        ]))->assertSessionHasErrors('payments');
    }

    public function test_counterparty_voen_is_unique_per_company(): void
    {
        $admin = $this->makeCompany();
        $other = $this->makeCompany('Başqa MMC', 'x@basqa.az');
        $data = ['type' => 'customer', 'entity_type' => 'legal', 'name' => 'A', 'voen' => '1234567891', 'country' => 'Azərbaycan'];

        $this->actingAs($admin)->post(route('counterparties.store'), $data)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('counterparties.store'), ['name' => 'B'] + $data)->assertSessionHasErrors('voen');
        // Same VÖEN in another company is fine.
        $this->actingAs($other)->post(route('counterparties.store'), $data)->assertSessionHasNoErrors();
    }

    public function test_bank_transaction_uses_cbar_and_records_exchange_difference(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $account = $this->inTenant($admin, fn () => BankAccount::create(['name' => 'USD', 'bank_name' => 'B', 'currency' => 'USD', 'opening_balance' => 0]));
        $date = CarbonImmutable::today()->subDays(3)->toDateString();

        $this->actingAs($admin)->post(route('bank.transactions.store'), [
            'mode' => 'regular', 'bank_account_id' => $account->id, 'direction' => 'in', 'transaction_date' => $date,
            'amount' => '1 000', 'override_rate' => '1', 'applied_rate' => '1,6900',
        ])->assertSessionHasNoErrors();

        $tx = $this->inTenant($admin, fn () => BankTransaction::firstOrFail());
        $this->assertSame('USD', $tx->currency);
        $this->assertSame(1690.0, (float) $tx->amount_azn);
        $this->assertSame(round(1000 * (float) $tx->cbar_rate, 2), (float) $tx->cbar_amount_azn);
        $this->assertEqualsWithDelta(1690 - (float) $tx->cbar_amount_azn, $tx->exchangeDifference(), 0.001);
        $this->assertSame(1000.0, $this->inTenant($admin, fn () => $account->fresh()->balance()));
    }

    public function test_conversion_creates_two_linked_legs_and_delete_removes_both(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$usd, $azn] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'USD', 'bank_name' => 'B', 'currency' => 'USD', 'opening_balance' => 5000]),
            BankAccount::create(['name' => 'AZN', 'bank_name' => 'B', 'currency' => 'AZN', 'opening_balance' => 0]),
        ]);
        $this->actingAs($admin)->post(route('bank.transactions.store'), [
            'mode' => 'transfer', 'from_account_id' => $usd->id, 'to_account_id' => $azn->id,
            'transaction_date' => CarbonImmutable::today()->subDays(3)->toDateString(), 'amount_out' => '1000', 'amount_in' => '1695',
        ])->assertSessionHasNoErrors();

        $legs = $this->inTenant($admin, fn () => BankTransaction::orderBy('id')->get());
        $this->assertCount(2, $legs);
        $this->assertSame('conversion', $legs[0]->kind);
        $this->assertSame($legs[0]->transfer_group, $legs[1]->transfer_group);
        $this->assertSame(4000.0, $this->inTenant($admin, fn () => $usd->fresh()->balance()));
        $this->assertSame(1695.0, $this->inTenant($admin, fn () => $azn->fresh()->balance()));

        $this->delete(route('bank.transactions.destroy', $legs[1]))->assertRedirect();
        $this->assertSame(0, $this->inTenant($admin, fn () => BankTransaction::count()));
    }

    public function test_conversion_without_received_amount_is_rejected(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        [$usd, $azn] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'USD', 'bank_name' => 'B', 'currency' => 'USD', 'opening_balance' => 0]),
            BankAccount::create(['name' => 'AZN', 'bank_name' => 'B', 'currency' => 'AZN', 'opening_balance' => 0]),
        ]);
        $this->actingAs($admin)->post(route('bank.transactions.store'), ['mode' => 'transfer', 'from_account_id' => $usd->id, 'to_account_id' => $azn->id, 'transaction_date' => today()->subDay()->toDateString(), 'amount_out' => '10'])
            ->assertSessionHasErrors('amount_in');
    }

    public function test_every_change_is_audited_with_old_and_new_values(): void
    {
        $admin = $this->makeCompany();
        $this->actingAs($admin)->post(route('counterparties.store'), ['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Köhnə ad', 'country' => 'Azərbaycan']);
        $cp = $this->inTenant($admin, fn () => Counterparty::firstOrFail());
        $this->put(route('counterparties.update', $cp), ['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Yeni ad', 'country' => 'Azərbaycan'])->assertRedirect();
        $this->delete(route('counterparties.destroy', $cp))->assertRedirect();

        $logs = $this->inTenant($admin, fn () => \App\Models\AuditLog::where('auditable_type', 'counterparty')->orderBy('id')->get());
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame('Köhnə ad', $logs[1]->old_values['name']);
        $this->assertSame('Yeni ad', $logs[1]->new_values['name']);
        $this->assertSame($admin->id, $logs[1]->user_id);
    }
}
