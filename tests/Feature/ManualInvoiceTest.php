<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Support\CounterpartyLedger;
use App\Support\DealObligations;
use App\Support\Profit\ProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fakturasız: only the Total, then logistics, commission and the RUB conversion as usual — no lines, no documents. */
class ManualInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function setUpDeal(): array
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $deal = $this->inTenant($admin, function () {
            $seller = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Ellis GmbH', 'country' => 'Almaniya']);
            $buyer = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Axios LLC', 'country' => 'Russia']);
            $purchase = Contract::create(['number' => 'P-1', 'contract_date' => today(), 'counterparty_id' => $seller->id, 'kind' => 'purchase', 'subject' => 'X', 'amount' => 1, 'currency' => 'EUR', 'status' => 'active']);
            $sale = Contract::create(['number' => 'S-1', 'contract_date' => today(), 'counterparty_id' => $buyer->id, 'kind' => 'sale', 'subject' => 'X', 'amount' => 1, 'currency' => 'RUB', 'status' => 'active']);
            $p = Project::create(['code' => 'P', 'name' => 'P', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);

            return Deal::create(['project_id' => $p->id, 'code' => 'TD-9', 'title' => 'T', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'active',
                'supplier_id' => $seller->id, 'purchase_contract_id' => $purchase->id, 'counterparty_id' => $buyer->id, 'sale_contract_id' => $sale->id]);
        });
        $this->actingAs($admin);

        return [$admin, $deal];
    }

    public function test_amounts_logistics_and_rub_give_the_final_figure_without_documents(): void
    {
        [$admin, $deal] = $this->setUpDeal();
        $date = today()->subDays(3)->toDateString();

        $this->post(route('invoices.manual', $deal), ['invoice_date' => $date, 'currency' => 'EUR', 'amount' => ''])->assertSessionHasErrors('amount');

        $this->post(route('invoices.manual', $deal), ['invoice_date' => $date, 'currency' => 'EUR', 'amount' => '10 000,00'])->assertSessionHasNoErrors();
        $inv = $this->inTenant($admin, fn () => Invoice::where('entry_mode', 'manual')->firstOrFail());
        $this->assertSame(['M-TD-9-1', '10000.00', null, 1], [$inv->number, $inv->total, $inv->commission_total, $inv->items()->count()]);

        $this->post(route('invoices.commission', $inv), ['commission_rate' => '3,5'])->assertSessionHasNoErrors();   // the usual commission step: 350
        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'forecast', 'logistics_method' => 'total', 'logistics_amount' => '650', 'logistics_currency' => 'EUR'])->assertSessionHasNoErrors();
        $this->post(route('invoices.rub', $inv), ['fx_source' => 'forecast', 'fx_date' => today()->addDays(5)->toDateString(), 'fx_base_azn' => '2,0005', 'fx_target_azn' => '0,0211'])->assertSessionHasNoErrors();

        $inv = $this->inTenant($admin, fn () => Invoice::findOrFail($inv->id));
        $sale = $this->inTenant($admin, fn () => $inv->saleTotal());
        $this->assertEqualsWithDelta(round(11000 * 2.0005 / 0.0211, 2), $sale, 0.02, '(10 000 + 650 logistics + 350 commission) × EUR/RUB');
        $this->assertSame(0, $this->inTenant($admin, fn () => SalesDocument::count()), 'no documents for a manual entry');

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Fakturasız')->assertSee(money($sale, 'RUB'))->assertDontSee('Alıcı üçün sənədlər');
        $this->get(route('deals.show', [$deal, 'tab' => 'invoices']))->assertOk()->assertSee('M-TD-9-1')->assertSee('Fakturasız');
        $this->post(route('invoices.documents', $inv))->assertSessionHas('error');

        $this->inTenant($admin, function () use ($deal, $sale) {
            $deal = Deal::findOrFail($deal->id);
            $ob = DealObligations::for($deal);
            $this->assertSame(['RUB' => $sale], $ob['buyer']['billed'], 'the buyer is billed the final figure');
            $this->assertSame(['EUR' => 10000.0], $ob['seller']['invoiced'], 'we owe the seller its own amount');
            $this->assertSame(['EUR' => 650.0], $ob['logistics']['due']);

            $this->assertSame(['RUB' => $sale], CounterpartyLedger::for($deal->counterparty)['balances']);
            $this->assertSame(['EUR' => -10000.0], CounterpartyLedger::for($deal->supplier)['balances']);

            $row = app(ProfitCalculator::class)->deal($deal)['rows'][0];
            $this->assertSame([$sale, 'RUB'], [$row['H'], $row['saleCur']]);
            $this->assertNotNull($row['forecast']);
            $this->assertEqualsWithDelta($sale * 0.0211 - 10000 * 2.0005 - 650 * 2.0005 - $row['forecast']['S'], $row['forecast']['profit'], 0.01);
        });
    }
}
