<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Logistics cost entered after the import: split by Total share, or per line in one currency. */
class InvoiceLogisticsTest extends TestCase
{
    use RefreshDatabase;

    /** The nine lines of the company's sheet (Total/EUR) and its Logistics column for 10 200. */
    private const TOTALS = [15648, 35456, 81992, 8864, 17728, 5848, 17920, 3130, 5336];

    private const SHEET_LOGISTICS = [832, 1884, 4358, 471, 942, 311, 952, 166, 284];

    /** Quantity (E) of the same nine lines. */
    private const QTY = [3200, 3200, 7400, 800, 1600, 400, 800, 200, 800];

    private function invoice(): array
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $inv = $this->inTenant($admin, function () {
            $seller = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Satıcı', 'country' => 'Almaniya']);
            $c = Contract::create(['number' => 'P-1', 'contract_date' => today(), 'counterparty_id' => $seller->id, 'kind' => 'purchase', 'subject' => 'X', 'amount' => 1, 'currency' => 'EUR', 'status' => 'active']);
            $p = Project::create(['code' => 'P', 'name' => 'P', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);
            $deal = Deal::create(['project_id' => $p->id, 'code' => 'TD-1', 'title' => 'T', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'invoiced', 'supplier_id' => $seller->id, 'purchase_contract_id' => $c->id]);
            $inv = $deal->invoices()->create(['project_id' => $p->id, 'type' => 'supplier', 'number' => '221619', 'invoice_date' => today()->subDays(3),
                'counterparty_id' => $seller->id, 'contract_id' => $c->id, 'currency' => 'EUR', 'total' => array_sum(self::TOTALS), 'status' => 'draft']);
            foreach (self::TOTALS as $i => $t) {
                $inv->items()->create(['line_no' => $i + 1, 'description' => 'Məhsul '.($i + 1), 'quantity' => self::QTY[$i], 'uom' => 'kg', 'unit_price' => round($t / self::QTY[$i], 4), 'total' => $t]);
            }

            return $inv;
        });

        return [$admin, $inv];
    }

    public function test_total_amount_is_split_like_the_excel_formula(): void
    {
        [$admin, $inv] = $this->invoice();
        $this->actingAs($admin)->post(route('invoices.logistics', $inv), [
            'logistics_mode' => 'forecast', 'logistics_method' => 'total', 'logistics_amount' => '10 200', 'logistics_currency' => 'EUR',
        ])->assertSessionHasNoErrors();

        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $lines = $inv->items->pluck('logistics')->map(fn ($v) => (float) $v)->all();
        $this->assertSame(10200.0, round(array_sum($lines), 2), 'lines add up exactly to the amount');
        $this->assertSame(self::SHEET_LOGISTICS, array_map(fn ($v) => (int) round($v), $lines), 'same as the sheet (=H*$I$12/$H$12)');
        $this->assertEqualsWithDelta(15648 * 10200 / 191922, $lines[0], 0.01);
        $this->assertSame('forecast', $inv->logistics_mode);
        $this->assertSame(1.0, (float) $inv->logistics_rate);

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Proqnoz')->assertSee(money(10200, 'EUR'), false);
        $this->get(route('invoices.export', [$inv, 'format' => 'xlsx']))->assertOk();
    }

    public function test_amount_in_another_currency_is_converted_with_cbar(): void
    {
        [$admin, $inv] = $this->invoice();
        $this->actingAs($admin)->post(route('invoices.logistics', $inv), [
            'logistics_mode' => 'actual', 'logistics_method' => 'total', 'logistics_amount' => '10000', 'logistics_currency' => 'AZN',
        ])->assertSessionHasNoErrors();

        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $eur = app(CurrencyRates::class)->rate('EUR', $inv->invoice_date);
        $this->assertEqualsWithDelta(1 / $eur, (float) $inv->logistics_rate, 1e-7);
        $this->assertSame(10000.0, (float) $inv->logistics_amount);
        $this->assertEqualsWithDelta(round(10000 / $eur, 2), (float) $inv->logistics_total, 0.01);
        $this->assertSame(10000.0, round($inv->items->sum(fn ($i) => (float) $i->logistics_original), 2));
        $this->assertSame((float) $inv->logistics_total, round($inv->items->sum(fn ($i) => (float) $i->logistics), 2));
    }

    public function test_per_item_amounts_must_share_one_currency(): void
    {
        [$admin, $inv] = $this->invoice();
        $ids = $this->inTenant($admin, fn () => $inv->items()->pluck('id')->all());
        $amounts = array_combine($ids, array_map(fn ($i) => (string) (100 + $i), range(1, 9)));
        $this->actingAs($admin);

        // One line in EUR, the rest in RUB: refused.
        $mixed = array_fill_keys($ids, 'RUB');
        $mixed[$ids[3]] = 'EUR';
        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'actual', 'logistics_method' => 'per_item', 'items' => $amounts, 'item_currency' => $mixed])
            ->assertSessionHasErrors('item_currency');

        // A line without an amount: refused.
        $missing = $amounts;
        $missing[$ids[0]] = '';
        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'actual', 'logistics_method' => 'per_item', 'items' => $missing, 'item_currency' => array_fill_keys($ids, 'EUR')])
            ->assertSessionHasErrors('items.'.$ids[0]);

        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'actual', 'logistics_method' => 'per_item', 'items' => $amounts, 'item_currency' => array_fill_keys($ids, 'EUR')])
            ->assertSessionHasNoErrors();
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $this->assertSame('per_item', $inv->logistics_method);
        $this->assertSame(101.0, (float) $inv->items[0]->logistics);
        $this->assertSame((float) array_sum(array_map('floatval', $amounts)), (float) $inv->logistics_total);

        $this->delete(route('invoices.logistics.clear', $inv))->assertRedirect();
        $this->assertNull($this->inTenant($admin, fn () => Invoice::find($inv->id)->logistics_method));
    }

    public function test_the_page_offers_both_ways(): void
    {
        [$admin, $inv] = $this->invoice();
        $this->actingAs($admin)->get(route('invoices.show', $inv))->assertOk()
            ->assertSee('Logistika xərci')->assertSee('Ümumi məbləğ')->assertSee('Hər məhsul üzrə')
            ->assertSee('Siz daha öncəki məhsulda', false);
    }

    /**
     * The company's sheet with logistics 10 200 € and a 3.5% commission:
     * J = (I+H)/E, K = H×3.5%, L = (H+I+K)/E, M = H+I+K — values read off the sheet.
     */
    public function test_commission_and_ccl_columns_match_the_sheet(): void
    {
        [$admin, $inv] = $this->invoice();
        $this->actingAs($admin);

        // Commission first (allowed before logistics): K is there, CCL is not yet.
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '3,5'])->assertSessionHasNoErrors();
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $this->assertSame([547.68, 1240.96, 2869.72, 310.24, 620.48, 204.68, 627.2, 109.55, 186.76], $inv->items->map(fn ($i) => (float) $i->commission)->all());
        $this->assertSame(6717.27, (float) $inv->commission_total);
        $this->assertNull($inv->items[0]->totalCcl());
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Commission 3,5%')->assertSee('UNIT PRICE+LOG və CCL sütunları üçün logistika');

        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'actual', 'logistics_method' => 'total', 'logistics_amount' => '10200', 'logistics_currency' => 'EUR'])
            ->assertSessionHasNoErrors();
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $r2 = fn ($v) => round($v, 2);
        $this->assertSame([5.15, 11.67, 11.67, 11.67, 11.67, 15.4, 23.59, 16.48, 7.02], $inv->items->map(fn ($i) => $r2($i->unitPriceLog()))->all(), 'J');
        $this->assertSame([5.32, 12.06, 12.06, 12.06, 12.06, 15.91, 24.37, 17.03], array_slice($inv->items->map(fn ($i) => $r2($i->unitPriceCcl()))->all(), 0, 8), 'L');
        $this->assertSame(17027.32, round($inv->items[0]->totalCcl(), 2), 'M of line 1');
        $this->assertSame(208839.27, round($inv->items->sum(fn ($i) => $i->totalCcl()), 2), 'M total = 208 839.27 as on the sheet');

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee(num(208839.27))->assertSee('EUR → RUB konvertasiya', false);

        // RUR columns: N = L × D18/D19 with the sheet's D18 = 2.0005 (EUR), D19 = 0.0211 (RUB),
        // entered as a forecast for a date CBAR has not published yet.
        $issue = today()->addDays(10)->toDateString();
        $this->post(route('invoices.rub', $inv), ['fx_source' => 'forecast', 'fx_date' => $issue, 'fx_forecast' => (string) (2.0005 / 0.0211)])->assertSessionHasNoErrors();
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $this->assertSame([504.49, 1143.1, 1143.1, 1143.1, 1143.1, 1508.31, 2310.96, 1614.57, 688.13], $inv->items->map(fn ($i) => $i->unitPriceRubRounded())->all(), 'N / P');
        $this->assertSame([1614367.27, 3657911.93, 8458921.34, 914477.98, 1828955.96, 603324.37, 1848764.15, 322914.72, 550502.54],
            $inv->items->map(fn ($i) => round($i->totalRub(), 2))->all(), 'O, every line as on the sheet');
        $this->assertSame(1614368.0, $inv->items[0]->totalRubRounded(), 'Q of line 1');
        $this->assertSame(19800140.27, round($inv->items->sum(fn ($i) => $i->totalRub()), 2), 'O total as on the sheet');
        $this->assertSame(19800178.0, round($inv->items->sum(fn ($i) => $i->totalRubRounded()), 2), 'Q total as on the sheet');
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee(num(19800178))->assertSee(num(19800140.27))
            ->assertSee('(proqnoz)')->assertSee('CBAR kursu bu tarix üçün hələ dərc olunmayıb');
        $this->get(route('invoices.export', [$inv, 'format' => 'xlsx']))->assertOk();
        $this->get(route('invoices.export', [$inv, 'format' => 'pdf']))->assertOk();

        // A forecast whose day has come shows what CBAR actually published.
        $day = $inv->invoice_date->toDateString();
        $this->post(route('invoices.rub', $inv), ['fx_source' => 'forecast', 'fx_date' => $day, 'fx_forecast' => '90'])->assertSessionHasNoErrors();
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('CBAR faktiki');

        // From CBAR: both rates of the chosen day's bulletin; future dates refused (no other day's rate).
        $this->post(route('invoices.rub', $inv), ['fx_source' => 'cbar', 'fx_date' => $day])->assertSessionHasNoErrors();
        $inv = $this->inTenant($admin, fn () => Invoice::find($inv->id));
        $rates = app(CurrencyRates::class);
        $this->assertEqualsWithDelta($rates->rate('EUR', $day) / $rates->rate('RUB', $day), (float) $inv->fx_rate, 1e-9);
        $this->post(route('invoices.rub', $inv), ['fx_source' => 'cbar', 'fx_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('fx_date');
        $this->post(route('invoices.rub', $inv), ['fx_source' => 'forecast', 'fx_date' => $issue, 'fx_forecast' => '0'])->assertSessionHasErrors('fx_forecast');
        $this->getJson('/ajax/cross-rate?from=EUR&date='.$day)->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/ajax/cross-rate?from=EUR&date='.$issue)->assertOk()->assertJson(['ok' => false]);
        $this->delete(route('invoices.rub.clear', $inv))->assertRedirect();
        $this->assertFalse($this->inTenant($admin, fn () => Invoice::find($inv->id))->hasRub());
        $this->get(route('invoices.export', [$inv, 'format' => 'xlsx']))->assertOk();
        $this->get(route('invoices.export', [$inv, 'format' => 'pdf']))->assertOk();

        // Another rate re-applies to every line; bad input is refused; clear removes it.
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '2'])->assertSessionHasNoErrors();
        $this->assertSame(3838.44, (float) $this->inTenant($admin, fn () => Invoice::find($inv->id)->commission_total));
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '150'])->assertSessionHasErrors('commission_rate');
        $this->post(route('invoices.commission', $inv), ['commission_rate' => 'abc'])->assertSessionHasErrors('commission_rate');
        $this->delete(route('invoices.commission.clear', $inv))->assertRedirect();
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $this->assertFalse($inv->hasCommission());
        $this->assertNull($inv->items[0]->commission);
    }
}
