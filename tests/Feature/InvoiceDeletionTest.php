<?php

namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** An imported seller invoice can be deleted (with its documents) and the same proforma imported again. */
class InvoiceDeletionTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_imported_invoice_can_be_deleted(): void
    {
        config(['glaust.invoice_approval_flow' => false]);
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);

        $this->assertGreaterThan(0, $this->inTenant($admin, fn () => \App\Models\SalesDocument::count()));
        $this->get(route('deals.show', [$inv->deal_id, 'tab' => 'invoices']))->assertOk()->assertSee('Faktura 221619, onun sətirləri və alıcı üçün sənədləri silinəcək.');   // delete right in the list
        $this->delete(route('invoices.destroy', $inv))->assertRedirect(route('deals.show', $inv->deal_id))->assertSessionHas('success');
        $this->assertNull($this->inTenant($admin, fn () => Invoice::find($inv->id)));
        $this->assertSame(0, $this->inTenant($admin, fn () => \App\Models\SalesDocument::count()), 'its buyer documents went with it');

        // the same number can come in again
        $this->post(route('invoices.manual', $inv->deal_id), ['number' => '221619', 'invoice_date' => today()->toDateString(), 'currency' => 'EUR', 'amount' => '1000'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inTenant($admin, fn () => Invoice::where('number', '221619')->count()));
    }
}
