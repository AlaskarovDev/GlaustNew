<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** The seller invoice: a product code per line; its own freight (NAVLUN) and discount % spread over the lines by Total. */
class InvoiceTermsTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_the_product_code_is_imported_before_the_description(): void
    {
        Storage::fake('local');
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $deal = $this->inTenant($admin, function () {
            $seller = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Satıcı', 'country' => 'Türkiyə']);
            $purchase = Contract::create(['number' => 'P-1', 'contract_date' => today(), 'counterparty_id' => $seller->id, 'kind' => 'purchase', 'subject' => 'X', 'amount' => 1, 'currency' => 'EUR', 'status' => 'active']);
            $p = Project::create(['code' => 'P', 'name' => 'P', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);

            return Deal::create(['project_id' => $p->id, 'code' => 'TD-1', 'title' => 'T', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'draft',
                'supplier_id' => $seller->id, 'purchase_contract_id' => $purchase->id]);
        });
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray(['Proforma N', 'N', 'Product Code', 'Description', 'HS Code', 'Quantity', 'UOM', 'Unit Price', 'Total/EUR'], null, 'A1');
        $s->fromArray([
            ['A-77', 1, 9615223136, 'VALVE UNIQUE RV-P 38 KV16 HNBR NO 316L', '848180', 1, 'Adet', 2317.25, 2317.25],
            ['A-77', 2, '', 'NON-RETURN VALVE LKC-2-ISO-W-63.5-EPDM-316L', '848180', 1, 'Adet', 256.45, 256.45],
        ], null, 'A2', true);
        $path = tempnam(sys_get_temp_dir(), 'inv').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($admin)->post(route('invoices.import', $deal), ['file' => new UploadedFile($path, 'f.xlsx', null, null, true), 'invoice_date' => now()->subDay()->toDateString()])
            ->assertSessionHasNoErrors()->assertSessionMissing('error');
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->firstOrFail());
        $this->assertSame(['9615223136', null], $inv->items->pluck('product_code')->all(), 'a number stays as written; empty is allowed');
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Product Code')->assertSee('9615223136')->assertSee('Faktura daxili navlun və endirim');
    }

    public function test_freight_and_discount_are_spread_by_total_and_can_be_removed(): void
    {
        [$admin, $inv] = $this->calculated();   // goods 191 922, logistics 10 200 by Total, commission 3,5 %

        // 10 % discount and 700 EUR freight
        $this->post(route('invoices.terms', $inv), ['discount_percent' => '10', 'freight_amount' => '700'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $inv = $this->inTenant($admin, fn () => Invoice::with('items')->find($inv->id));
        $first = $inv->items->firstWhere('line_no', 1);
        $this->assertSame(173429.8, (float) $inv->total, '191 922 − 19 192,20 + 700');
        $this->assertSame(19192.2, (float) $inv->discount_amount);
        $this->assertSame([15648.0, 1564.8, 57.07, 14140.27], [(float) $first->base_total, (float) $first->discount, (float) $first->freight, (float) $first->total]);
        $this->assertSame(round(14140.27 / 3200, 4), (float) $first->unit_price);
        $this->assertSame(4.89, (float) $first->base_unit_price, 'the seller\'s price is kept');
        $this->assertSame(700.0, round((float) $inv->items->sum('freight'), 2), 'the freight adds up');
        $this->assertSame(173429.8, round((float) $inv->items->sum('total'), 2));
        $this->assertSame(494.91, (float) $first->commission, 'the commission follows the new Total');
        $this->assertSame(10200.0, round((float) $inv->items->sum('logistics'), 2));
        $this->assertSame(round(10200 * 14140.27 / 173429.8, 2), (float) $first->logistics, 'the logistics is spread by the new Totals');
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Navlun')->assertSee(num(15648))->assertSee('−'.num(1564.8))->assertSee('+57,07');

        // only the discount
        $this->post(route('invoices.terms', $inv), ['discount_percent' => '5', 'freight_amount' => ''])->assertSessionHasNoErrors();
        $this->inTenant($admin, function () use ($inv) {
            $i = Invoice::with('items')->find($inv->id);
            $this->assertSame(round(191922 * 0.95, 2), (float) $i->total);
            $this->assertNull($i->freight_amount);
        });

        // both empty: the seller's own figures again
        $this->post(route('invoices.terms', $inv), ['discount_percent' => '', 'freight_amount' => ''])->assertSessionHasNoErrors();
        $this->inTenant($admin, function () use ($inv) {
            $i = Invoice::with('items')->find($inv->id);
            $first = $i->items->firstWhere('line_no', 1);
            $this->assertSame(191922.0, (float) $i->total);
            $this->assertSame([15648.0, 4.89, null, null], [(float) $first->total, (float) $first->unit_price, $first->base_total, $first->freight]);
            $this->assertSame(547.68, (float) $first->commission);
        });

        $this->post(route('invoices.terms', $inv), ['discount_percent' => '100'])->assertSessionHasErrors('discount_percent');
    }
}
