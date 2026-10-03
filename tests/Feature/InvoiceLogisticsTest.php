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
                $inv->items()->create(['line_no' => $i + 1, 'description' => 'Məhsul '.($i + 1), 'quantity' => 100, 'uom' => 'kg', 'unit_price' => $t / 100, 'total' => $t]);
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
}
