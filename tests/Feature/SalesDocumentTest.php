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
        $this->assertSame(2, $this->inTenant($admin, fn () => SalesDocument::count()));

        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Alıcı üçün sənədlər')->assertSee($pf->number);
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
        $this->assertSame(1, $this->inTenant($admin, fn () => SalesDocument::count()));
        $this->post(route('invoices.documents', $inv))->assertSessionHas('success');
        $this->assertSame(2, $this->inTenant($admin, fn () => SalesDocument::count()));
        $this->assertSame(1, $this->inTenant($admin, fn () => Invoice::count()));
    }
}
