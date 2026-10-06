<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Support\RuMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Proforma (EN) + specification (RU) for the buyer: generated from the calculation, then editable. */
class SalesDocumentTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_documents_are_created_when_the_calculation_completes(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->assertSame(0, $this->inTenant($admin, fn () => SalesDocument::count()), 'nothing before the RUB step');
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('avtomatik yaranacaq');

        $this->applyRub($inv)->assertSessionHasNoErrors()->assertSessionHas('documents_created');
        [$pf, $sp] = $this->inTenant($admin, fn () => [SalesDocument::where('kind', 'proforma')->firstOrFail(), SalesDocument::where('kind', 'specification')->firstOrFail()]);

        // Proforma = the PROFORMA sheet: P as unit price, P × E as total, 19 800 178.00 in all.
        $this->assertSame([504.49, 1143.1, 1143.1, 1143.1, 1143.1, 1508.31, 2310.96, 1614.57, 688.13], array_map(fn ($l) => (float) $l['unit_price'], $pf->lines));
        $this->assertSame(1614368.0, (float) $pf->lines[0]['total']);
        $this->assertSame(19800178.0, $pf->grandTotal());
        $this->assertSame('03CCLRU/010223', $pf->contract_number);
        $this->assertSame('09.02.2023', $pf->contract_date);
        $this->assertStringContainsString('CCL Kontur LLC', $pf->customer_block);
        $this->assertStringStartsWith('P1/', $pf->number);

        // Specification: same number, Russian units.
        $this->assertSame($pf->number, $sp->number);
        $this->assertSame('кг', $sp->lines[0]['uom']);
        $this->assertSame('100% предоплата', $sp->payment_terms);

        // Re-applying a step never overwrites existing (possibly edited) documents.
        $this->applyRub($inv)->assertSessionMissing('documents_created');
        $this->assertSame(3, $this->inTenant($admin, fn () => SalesDocument::count()), 'proforma, specification, packing list');

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Alıcı üçün sənədlər')->assertSee($pf->number)
            ->assertSee('Satıcının fakturası')->assertSee(money(191922, 'EUR'))->assertSee('Alıcıya fakturamız')->assertSee(money(19800178, 'RUB'))->assertSee('AZN ilə göstər');
        $this->get(route('deals.show', $inv->deal_id))->assertOk()->assertSee('Proforma Invoice');
    }

    public function test_pdfs_render_from_the_current_state(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        foreach ($this->inTenant($admin, fn () => SalesDocument::all()) as $doc) {
            $this->get(route('sales-documents.show', $doc))->assertOk()->assertSee('Redaktə edilə bilən sənəd');
            $res = $this->get(route('sales-documents.pdf', $doc));
            $res->assertOk();
            $this->assertStringStartsWith('%PDF', $res->getContent());
            $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
            $this->assertStringContainsString('inline', (string) $this->get(route('sales-documents.pdf', [$doc, 'inline' => 1]))->headers->get('content-disposition'));
        }
        $this->assertSame('девятнадцать миллионов восемьсот тысяч сто семьдесят восемь рублей ноль копеек', RuMoney::words(19800178));
    }

    /** The SP sheet's hand edits: line 8 → 1614.92, line 9 → 7960 м2 × 69.15 — total kept at 19 800 178. */
    public function test_specification_lines_are_editable_and_history_is_kept(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        $sp = $this->inTenant($admin, fn () => SalesDocument::where('kind', 'specification')->firstOrFail());

        $lines = $sp->lines;
        $lines[7]['unit_price'] = '1614,92';
        $lines[8] = ['description' => $lines[8]['description'], 'hs_code' => $lines[8]['hs_code'], 'uom' => 'м2', 'quantity' => '7 960', 'unit_price' => '69,15'];
        $this->put(route('sales-documents.update', $sp), [
            'number' => $sp->number, 'doc_date' => $sp->doc_date->toDateString(), 'contract_number' => $sp->contract_number, 'contract_date' => $sp->contract_date,
            'payment_terms' => '100% предоплата', 'delivery_terms' => 'DAP 21 недель (150 календарных дней)',
            'seller_signatory' => 'Генеральный директор Gadirov Ayaz', 'buyer_signatory' => 'Генеральный директор Батыркаев М.Х.',
            'lines' => $lines,
        ])->assertSessionHasNoErrors();

        $sp = $this->inTenant($admin, fn () => SalesDocument::find($sp->id));
        $this->assertSame(322984.0, (float) $sp->lines[7]['total']);
        $this->assertSame(550434.0, (float) $sp->lines[8]['total']);
        $this->assertSame(19800178.0, (float) $sp->total, 'same as the proforma, as on the SP sheet');
        $this->assertSame('Генеральный директор Gadirov Ayaz', $sp->seller_signatory);

        $this->assertTrue($this->inTenant($admin, fn () => AuditLog::where('auditable_type', 'sales_document')->where('auditable_id', $sp->id)->where('action', 'updated')->exists()));
        $this->get(route('sales-documents.show', $sp))->assertOk()->assertSee('Hesablamadan yenilə')->assertSee('Gadirov Ayaz');
        $this->assertStringStartsWith('%PDF', $this->get(route('sales-documents.pdf', $sp))->getContent());

        // A line can be removed; an empty document is refused.
        $this->put(route('sales-documents.update', $sp), ['number' => $sp->number, 'doc_date' => $sp->doc_date->toDateString(), 'lines' => []])
            ->assertSessionHasErrors('lines');

        // Refresh puts the calculation back; header fields stay.
        $this->post(route('sales-documents.refresh', $sp))->assertSessionHasNoErrors();
        $sp = $this->inTenant($admin, fn () => SalesDocument::find($sp->id));
        $this->assertSame(1614.57, (float) $sp->lines[7]['unit_price']);
        $this->assertSame('Генеральный директор Gadirov Ayaz', $sp->seller_signatory);
    }

    public function test_deleted_documents_can_be_recreated_and_other_tenants_cannot_see_them(): void
    {
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        $pf = $this->inTenant($admin, fn () => SalesDocument::where('kind', 'proforma')->firstOrFail());

        $other = $this->makeCompany('Digər MMC', 'other@test.az');
        $this->actingAs($other)->get(route('sales-documents.show', $pf))->assertNotFound();
        $this->actingAs($other)->get(route('sales-documents.pdf', $pf))->assertNotFound();

        $this->actingAs($admin)->delete(route('sales-documents.destroy', $pf))->assertRedirect();
        $this->assertSame(2, $this->inTenant($admin, fn () => SalesDocument::count()));
        $this->post(route('invoices.documents', $inv))->assertSessionHas('success');
        $this->assertSame(3, $this->inTenant($admin, fn () => SalesDocument::count()));
        $this->assertSame(1, $this->inTenant($admin, fn () => Invoice::count()));
    }

    /** Packing List (PL sheet): created with the proforma, products in pallet 1, pallets edited by hand, stays editable after the lock. */
    public function test_packing_list_with_pallets(): void
    {
        config(['glaust.invoice_approval_flow' => false]);
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        [$pf, $pl] = $this->inTenant($admin, fn () => [SalesDocument::where('kind', 'proforma')->firstOrFail(), SalesDocument::where('kind', 'packing')->firstOrFail()]);
        $this->assertSame($pf->number, $pl->number);
        $this->assertCount(1, $pl->pallets());
        $this->assertSame(count($pf->lines), count($pl->pallets()[0]['items']));
        $this->assertSame((float) $pf->lines[0]['quantity'], (float) $pl->pallets()[0]['items'][0]['total']);
        $this->assertSame('kg', $pl->pallets()[0]['items'][0]['total_unit']);

        $this->actingAs($admin)->get(route('sales-documents.show', $pl))->assertOk()->assertSee('Packing List')->assertSee('Proforma ilə yoxlama');
        $item = fn ($desc, $total) => ['code' => '510.1637.03', 'description' => $desc, 'package' => '200 kg', 'quantity' => (string) ($total / 200), 'qty_unit' => 'stck', 'total' => (string) $total, 'total_unit' => 'kg', 'weight' => '880'];
        $desc = $pf->lines[0]['description'];
        $this->put(route('sales-documents.update', $pl), [
            'number' => $pl->number, 'doc_date' => today()->toDateString(),
            'pallets' => [
                ['title' => 'Pallet №1', 'packing' => '4 colli 115 x 115 x 105', 'weight' => '905', 'items' => [$item($desc, 800)]],
                ['title' => 'Pallet №2', 'packing' => '4 colli 115 x 115 x 105', 'weight' => '905,5', 'items' => [$item($desc, 800), ['description' => '']]],
            ],
        ])->assertSessionHasNoErrors();
        $pl = $this->inTenant($admin, fn () => SalesDocument::find($pl->id));
        $this->assertSame(['pallets' => 2, 'weight' => 1810.5, 'packed' => 1760.0], $pl->packingTotals());
        $this->assertCount(1, $pl->pallets()[1]['items'], 'empty rows are dropped');
        $this->assertSame('2 palet · '.num(1810.5).' kg', $pl->summary());
        $this->put(route('sales-documents.update', $pl), ['number' => 'X', 'doc_date' => today()->toDateString(), 'pallets' => []])->assertSessionHasErrors('pallets');

        $pdf = $this->get(route('sales-documents.pdf', $pl));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));

        // locking issues the commercial invoice: the packing list takes its number and stays editable
        $this->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();
        [$ci, $pl] = $this->inTenant($admin, fn () => [SalesDocument::where('kind', 'commercial')->firstOrFail(), SalesDocument::find($pl->id)]);
        $this->assertSame($ci->number, $pl->number);
        $this->assertFalse($pl->isLocked());
        $this->put(route('sales-documents.update', $pl), [
            'number' => $pl->number, 'doc_date' => today()->toDateString(),
            'pallets' => [['title' => 'Pallet №1', 'packing' => '', 'weight' => '900', 'items' => [$item($desc, 800)]]],
        ])->assertSessionHasNoErrors();
        $this->get(route('deals.show', $inv->deal_id))->assertOk()->assertSee('Packing List');
    }

    /** Directors from company settings and the buyer's card sign the documents; a later change follows into documents not locked. */
    public function test_director_names_sign_the_documents(): void
    {
        [$admin, $inv] = $this->calculated();
        $buyer = $this->inTenant($admin, fn () => \App\Models\Deal::find($inv->deal_id)->counterparty);
        $this->actingAs($admin)->put(route('settings.company.update'), ['name' => 'Glaust Handel', 'director_name' => 'Ələsgərov İ.'])->assertSessionHasNoErrors();
        $this->inTenant($admin, fn () => $buyer->update(['director_name' => 'Dulinov E. V.']));
        $this->applyRub($inv);

        $docs = $this->inTenant($admin, fn () => SalesDocument::all()->keyBy('kind'));
        $this->assertSame('Генеральный директор Ələsgərov İ.', $docs['specification']->seller_signatory);
        $this->assertSame('Генеральный директор Dulinov E. V.', $docs['specification']->buyer_signatory);
        $this->assertSame('General Director Ələsgərov İ.', $docs['proforma']->seller_signatory);
        $this->assertSame('General Director Ələsgərov İ.', $docs['packing']->seller_signatory);

        // the buyer's director changes on its card: the specification follows
        $this->put(route('counterparties.update', $buyer), [
            'type' => $buyer->type, 'entity_type' => $buyer->entity_type, 'name' => $buyer->name, 'country' => $buyer->country ?: 'Russia', 'director_name' => 'Petrov A.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Генеральный директор Petrov A.', $this->inTenant($admin, fn () => SalesDocument::find($docs['specification']->id))->buyer_signatory);

        // a hand-typed signatory is kept
        $this->inTenant($admin, fn () => SalesDocument::find($docs['proforma']->id)->update(['seller_signatory' => 'CFO John Smith']));
        $this->put(route('settings.company.update'), ['name' => 'Glaust Handel', 'director_name' => 'Yeni Direktor'])->assertSessionHasNoErrors();
        $this->assertSame('CFO John Smith', $this->inTenant($admin, fn () => SalesDocument::find($docs['proforma']->id))->seller_signatory);
        $this->assertSame('Генеральный директор Yeni Direktor', $this->inTenant($admin, fn () => SalesDocument::find($docs['specification']->id))->seller_signatory);

        $this->get(route('sales-documents.pdf', $docs['proforma']))->assertOk();
        $this->get(route('counterparties.edit', $buyer))->assertOk()->assertSee('Direktorun adı, soyadı');
    }

    /** The Trade's sale currency drives the conversion step and the buyer documents; a new Trade takes the last one's sides. */
    public function test_sale_currency_and_prefill_from_the_last_trade(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => \App\Models\Deal::find($inv->deal_id));
        $this->inTenant($admin, fn () => $deal->update(['sale_currency' => 'USD']));
        $this->actingAs($admin)->get(route('invoices.show', $inv))->assertOk()->assertSee('EUR → USD');
        $this->applyRub($inv);   // forecast: 2,0005 per EUR, 0,0211 per unit of the sale currency
        $pf = $this->inTenant($admin, fn () => SalesDocument::where('kind', 'proforma')->firstOrFail());
        $this->assertSame('USD', $pf->currency);
        $this->get(route('deals.show', $deal))->assertOk()->assertSee('Satış valyutası');

        // the project names no sides here: the next Trade's form starts from this Trade's buyer, seller and currencies
        $project = $this->inTenant($admin, function () use ($deal) {
            $p = \App\Models\Project::find($deal->project_id);
            $p->update(['counterparty_id' => null, 'supplier_id' => null, 'sale_contract_id' => null, 'purchase_contract_id' => null]);

            return $p;
        });
        $this->get(route('deals.create', $project))->assertOk()
            ->assertSee($deal->counterparty->name)->assertSee($deal->supplier->name)->assertSee('value="USD" selected', false);
    }
}
