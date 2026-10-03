<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A project has a buyer side (customer + sale contract) and a supplier side (supplier + purchase contract). */
class ProjectContractsTest extends TestCase
{
    use RefreshDatabase;

    private function setupDeal($admin): array
    {
        return $this->inTenant($admin, function () {
            $buyer = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Alıcı MMC', 'country' => 'Azərbaycan']);
            $seller = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Göndərən MMC', 'country' => 'Azərbaycan']);
            $other = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Başqa Müştəri', 'country' => 'Azərbaycan']);
            $mk = fn ($cp, $kind, $n, $amount) => Contract::create(['number' => $n, 'contract_date' => today(), 'counterparty_id' => $cp->id, 'kind' => $kind,
                'subject' => 'Məhsul', 'amount' => $amount, 'currency' => 'AZN', 'cbar_rate' => 1, 'amount_azn' => $amount, 'status' => 'active']);

            return [
                'buyer' => $buyer, 'seller' => $seller, 'other' => $other,
                'sale' => $mk($buyer, 'sale', 'S-1', 10000), 'purchase' => $mk($seller, 'purchase', 'P-1', 7500), 'otherSale' => $mk($other, 'sale', 'S-2', 1),
            ];
        });
    }

    private function payload(array $extra): array
    {
        return array_merge(['code' => 'PRJ-1', 'name' => 'Tədarük layihəsi', 'status' => 'active', 'priority' => 'medium', 'currency' => 'AZN'], $extra);
    }

    public function test_project_links_both_contracts_and_shows_margin(): void
    {
        $admin = $this->makeCompany();
        $d = $this->setupDeal($admin);

        $this->actingAs($admin)->post(route('projects.store'), $this->payload([
            'counterparty_id' => $d['buyer']->id, 'sale_contract_id' => $d['sale']->id,
            'supplier_id' => $d['seller']->id, 'purchase_contract_id' => $d['purchase']->id,
        ]))->assertSessionHasNoErrors();

        $project = $this->inTenant($admin, fn () => Project::firstOrFail());
        $this->assertSame($d['sale']->id, $project->sale_contract_id);
        $this->assertSame($d['purchase']->id, $project->purchase_contract_id);
        $this->assertSame(2500.0, $project->contractMargin()['margin']);
        $this->assertSame(25.0, $project->contractMargin()['percent']);

        $this->get(route('projects.show', $project))->assertOk()
            ->assertSee('Məhsulu alan tərəf')->assertSee('Məhsulu göndərən tərəf')
            ->assertSee('S-1')->assertSee('P-1')->assertSee('Müqavilələr üzrə marja');
        $this->get(route('projects.edit', $project))->assertOk()->assertSee('S-1 · Məhsul');
    }

    public function test_contract_must_match_side_kind_and_party(): void
    {
        $admin = $this->makeCompany();
        $d = $this->setupDeal($admin);
        $this->actingAs($admin);

        // A purchase contract in the buyer slot.
        $this->post(route('projects.store'), $this->payload(['sale_contract_id' => $d['purchase']->id]))->assertSessionHasErrors('sale_contract_id');
        // Another customer's sale contract for this buyer.
        $this->post(route('projects.store'), $this->payload(['counterparty_id' => $d['buyer']->id, 'sale_contract_id' => $d['otherSale']->id]))->assertSessionHasErrors('sale_contract_id');
        // A customer on the supplier side.
        $this->post(route('projects.store'), $this->payload(['supplier_id' => $d['buyer']->id]))->assertSessionHasErrors('supplier_id');
        $this->assertSame(0, $this->inTenant($admin, fn () => Project::count()));

        // Contract chosen without its party: the party comes from the contract.
        $this->post(route('projects.store'), $this->payload(['purchase_contract_id' => $d['purchase']->id]))->assertSessionHasNoErrors();
        $this->assertSame($d['seller']->id, $this->inTenant($admin, fn () => Project::firstOrFail()->supplier_id));
    }

    public function test_contract_lookup_is_filtered_by_kind_and_party(): void
    {
        $admin = $this->makeCompany();
        $d = $this->setupDeal($admin);
        $this->actingAs($admin);

        $sales = $this->getJson(route('ajax.lookup', ['contracts', 'kind' => 'sale', 'counterparty_id' => $d['buyer']->id]))->json('results');
        $this->assertSame(['S-1'], array_map(fn ($r) => explode(' · ', $r['label'])[0], $sales));
        $this->assertSame($d['buyer']->id, $sales[0]['party_id']);

        $purchases = $this->getJson(route('ajax.lookup', ['contracts', 'kind' => 'purchase']))->json('results');
        $this->assertSame(['P-1'], array_map(fn ($r) => explode(' · ', $r['label'])[0], $purchases));
    }

    public function test_new_contract_from_project_section_fills_the_empty_slot(): void
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $d = $this->setupDeal($admin);
        $project = $this->inTenant($admin, fn () => Project::create($this->payload(['supplier_id' => $d['seller']->id])));
        $this->actingAs($admin);

        $this->get(route('contracts.create', ['project_id' => $project->id, 'kind' => 'purchase', 'counterparty_id' => $d['seller']->id]))
            ->assertOk()->assertSee('Məhsulun alışı');

        $this->post(route('contracts.store'), [
            'contract_date' => today()->toDateString(), 'counterparty_id' => $d['seller']->id, 'kind' => 'purchase', 'subject' => 'Alış',
            'amount' => '4000', 'currency' => 'AZN', 'status' => 'signed', 'project_id' => $project->id,
        ])->assertSessionHasNoErrors();

        $new = $this->inTenant($admin, fn () => Contract::where('subject', 'Alış')->firstOrFail());
        $this->assertSame($new->id, $project->fresh()->purchase_contract_id);
    }
}
