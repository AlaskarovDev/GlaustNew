<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Reminder;
use App\Models\SalesDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Təsdiq axını: submit -> approvers in order -> commercial invoice, everything locked. */
class InvoiceApprovalTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    private function ready(bool $chain = true): array
    {
        config(['glaust.invoice_approval_flow' => $chain]);
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        $first = $this->makeUser($admin->company, 'accountant', 'muhasib@test.az');
        $second = $this->makeUser($admin->company, 'manager', 'direktor@test.az');

        return [$admin, $inv, $first, $second];
    }

    private function flow($admin, array $users): void
    {
        $this->actingAs($admin)->put(route('settings.approvals.update'), ['steps' => array_map(fn ($u, $i) => ['user_id' => $u->id, 'title' => 'Addım '.($i + 1)], $users, array_keys($users))])
            ->assertSessionHasNoErrors();
    }

    private function fresh($admin, $inv): Invoice
    {
        return $this->inTenant($admin, fn () => Invoice::with('salesDocuments')->find($inv->id));
    }

    /** Chain off (default): approve & lock in one step, unlock with a reason, correct, re-form; total changes are kept. */
    public function test_lock_unlock_with_reason_and_reform(): void
    {
        [$admin, $inv] = $this->ready(false);
        $this->actingAs($admin)->get(route('invoices.show', $inv))->assertOk()->assertSee('Fakturanı təsdiqlə və kilidlə')->assertDontSee('Fakturanı təsdiqə göndər');
        $this->get(route('settings.index'))->assertDontSee('Təsdiq axını');

        $this->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();
        $inv = $this->fresh($admin, $inv);
        $this->assertTrue($inv->isLocked());
        $ci = $inv->salesDocuments->firstWhere('kind', 'commercial');
        $this->assertNotNull($ci);
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '5'])->assertSessionHas('error');

        // unlock needs a reason
        $this->post(route('invoices.approval.unlock', $inv), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('invoices.approval.unlock', $inv), ['reason' => 'Alıcı qiyməti dəyişdi'])->assertRedirect(route('invoices.show', [$inv, 'edit' => 1]));
        $inv = $this->fresh($admin, $inv);
        $this->assertSame('unlocked', $inv->approval_status);
        $this->assertFalse($inv->isLocked());
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Alıcı qiyməti dəyişdi')->assertSee('Yenidən formalaşdır və kilidlə');

        // correct: commission 3.5 % -> 5 %, refresh the documents, re-form
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '5'])->assertSessionHasNoErrors();
        $this->applyRub($inv);
        $pf = $inv->salesDocuments->firstWhere('kind', 'proforma');
        $sp = $inv->salesDocuments->firstWhere('kind', 'specification');
        $before = (float) $pf->total;
        $this->post(route('sales-documents.refresh', $pf))->assertSessionHasNoErrors();
        $this->post(route('sales-documents.refresh', $sp))->assertSessionHasNoErrors();
        $this->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();

        $inv = $this->fresh($admin, $inv);
        $this->assertTrue($inv->isApproved());
        $ci2 = $inv->salesDocuments->firstWhere('kind', 'commercial');
        $this->assertSame($ci->number, $ci2->number, 'the commercial invoice keeps its number');
        $this->assertGreaterThan((float) $ci->total, (float) $ci2->total);
        $revs = $this->inTenant($admin, fn () => \App\Models\SalesDocumentRevision::where('deal_id', $inv->deal_id)->get());
        $pfRev = $revs->firstWhere('sales_document_id', $pf->id);
        $this->assertEqualsWithDelta($before, (float) $pfRev->total_before, 0.001);
        $this->assertEqualsWithDelta((float) $pfRev->total_after - $before, (float) $pfRev->difference, 0.001);
        $ciRev = $revs->firstWhere('sales_document_id', $ci->id);
        $this->assertSame('Alıcı qiyməti dəyişdi', $ciRev->reason, 'the unlock reason travels to the commercial invoice change');

        $this->get(route('deals.show', $inv->deal_id))->assertOk()->assertSee('Proforma və Commercial Invoice məbləğləri')->assertSee('Alıcı qiyməti dəyişdi');
    }

    /** While unlocked the commercial invoice itself can be corrected; re-forming keeps those hand edits. */
    public function test_commercial_invoice_lines_editable_while_unlocked(): void
    {
        [$admin, $inv] = $this->ready(false);
        $this->actingAs($admin)->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();
        $ci = $this->fresh($admin, $inv)->salesDocuments->firstWhere('kind', 'commercial');
        $payload = fn ($price) => ['number' => $ci->number, 'doc_date' => today()->toDateString(),
            'lines' => [['description' => 'Corrected goods', 'hs_code' => '3215', 'uom' => 'kg', 'quantity' => '100', 'unit_price' => $price]]];

        $this->put(route('sales-documents.update', $ci), $payload('10'))->assertSessionHas('error');   // locked
        $this->get(route('sales-documents.show', $ci))->assertOk()->assertSee('Inv. Number')->assertDontSee('Спецификация №');

        $this->post(route('invoices.approval.unlock', $inv), ['reason' => 'Miqdar dəyişdi']);
        $this->put(route('sales-documents.update', $ci), $payload('12,5'))->assertSessionHasNoErrors();
        $this->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();

        $ci = $this->inTenant($admin, fn () => SalesDocument::find($ci->id));
        $this->assertSame(1250.0, (float) $ci->total, 'hand edits made while unlocked are kept');
        $this->assertSame('Corrected goods', $ci->lines[0]['description']);
        $this->assertTrue($ci->isLocked());
    }

    public function test_full_flow_issues_the_commercial_invoice_and_locks_everything(): void
    {
        [$admin, $inv, $first, $second] = $this->ready();

        // No flow yet: cannot submit.
        $this->actingAs($admin)->get(route('invoices.show', $inv))->assertOk()->assertSee('Təsdiq axını qurulmayıb');
        $this->post(route('invoices.approval.submit', $inv))->assertSessionHasErrors('approval');

        $this->get(route('settings.approvals'))->assertOk()->assertSee('Fakturanın təsdiq axını');
        $this->flow($admin, [$first, $second]);
        $this->post(route('invoices.approval.submit', $inv))->assertSessionHasNoErrors();
        $inv = $this->fresh($admin, $inv);
        $this->assertSame('pending', $inv->approval_status);
        $this->assertSame($first->id, $inv->currentApproverId());
        $this->assertTrue($this->inTenant($admin, fn () => Reminder::where('user_id', $first->id)->where('source', 'approval')->exists()), 'approver is notified');

        // Locked while pending: calculation and documents refuse changes.
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '5'])->assertSessionHas('error');
        $this->assertSame(3.5, (float) $this->fresh($admin, $inv)->commission_rate);
        $sp = $inv->salesDocuments->firstWhere('kind', 'specification');
        $this->put(route('sales-documents.update', $sp), ['number' => 'X', 'doc_date' => today()->toDateString(), 'lines' => $sp->lines])->assertSessionHas('error');
        $this->get(route('invoices.show', $inv))->assertOk()->assertSee('Faktura təsdiqdədir');

        // Only the current approver decides.
        $this->actingAs($second)->post(route('invoices.approval.decide', $inv), ['decision' => 'approve'])->assertSessionHasErrors('approval');
        $this->actingAs($first)->get(route('invoices.show', $inv))->assertOk()->assertSee('Sizin qərarınız gözlənilir');
        $this->post(route('invoices.approval.decide', $inv), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $this->fresh($admin, $inv)->currentApproverId());

        // Rejection needs a reason; it unlocks and tells the submitter.
        $this->actingAs($second)->post(route('invoices.approval.decide', $inv), ['decision' => 'reject'])->assertSessionHasErrors('comment');
        $this->post(route('invoices.approval.decide', $inv), ['decision' => 'reject', 'comment' => 'Kursu yoxlayın'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $this->fresh($admin, $inv)->approval_status);
        $this->assertTrue($this->inTenant($admin, fn () => Reminder::where('user_id', $admin->id)->where('title', 'like', '%geri qaytarıldı%')->exists()));
        $this->actingAs($admin)->get(route('invoices.show', $inv))->assertSee('Kursu yoxlayın');

        // Unlocked: edit the specification like the SP sheet, then resubmit and approve twice.
        $lines = $sp->lines;
        $lines[8] = ['description' => $lines[8]['description'], 'hs_code' => $lines[8]['hs_code'], 'uom' => 'м2', 'quantity' => '7960', 'unit_price' => '69,15'];
        $lines[7]['unit_price'] = '1614,92';
        $this->put(route('sales-documents.update', $sp), ['number' => $sp->number, 'doc_date' => $sp->doc_date->toDateString(), 'lines' => $lines])->assertSessionHasNoErrors();
        $this->post(route('invoices.approval.submit', $inv))->assertSessionHasNoErrors();
        $this->actingAs($first)->post(route('invoices.approval.decide', $inv), ['decision' => 'approve']);
        $this->actingAs($second)->post(route('invoices.approval.decide', $inv), ['decision' => 'approve', 'comment' => 'OK'])->assertSessionHasNoErrors();

        $inv = $this->fresh($admin, $inv);
        $this->assertSame('approved', $inv->approval_status);
        $this->assertSame('confirmed', $inv->status);
        $ci = $inv->salesDocuments->firstWhere('kind', 'commercial');
        $this->assertNotNull($ci, 'commercial invoice issued');
        $this->assertStringStartsWith('I1/', $ci->number);
        $this->assertSame('kg', $ci->lines[0]['uom'], 'English units on the invoice');
        $this->assertSame('qm', $ci->lines[8]['uom']);
        $this->assertSame(550434.0, (float) $ci->lines[8]['total'], 'lines of the edited specification');
        $this->assertSame(19800178.0, $ci->grandTotal(), 'INVOICE sheet total');

        // Approved: red notice, the commercial invoice downloads, nothing can be changed.
        $this->actingAs($admin)->get(route('invoices.show', $inv))->assertOk()
            ->assertSee('Fakturada düzəliş əməliyyatlarına icazə dayandırılıb')->assertSee('Commercial Invoice '.$ci->number);
        $pdf = $this->get(route('sales-documents.pdf', $ci));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Commercial', (string) $pdf->headers->get('content-disposition'));
        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'actual', 'logistics_method' => 'total', 'logistics_amount' => '1', 'logistics_currency' => 'EUR'])->assertSessionHas('error');
        $this->put(route('sales-documents.update', $ci), ['number' => 'X', 'doc_date' => today()->toDateString(), 'lines' => $ci->lines])->assertSessionHas('error');
        $this->delete(route('invoices.destroy', $inv))->assertSessionHas('error');
        $this->get(route('sales-documents.show', $ci))->assertOk()->assertSee('təsdiqdən sonra yaradılıb');
        $this->get(route('deals.show', $inv->deal_id))->assertOk()->assertSee('Commercial Invoice');
    }

    public function test_submitter_can_withdraw_and_settings_reject_duplicates(): void
    {
        [$admin, $inv, $first] = $this->ready();
        $this->flow($admin, [$first]);
        $this->put(route('settings.approvals.update'), ['steps' => [['user_id' => $first->id], ['user_id' => $first->id]]])->assertSessionHasErrors();

        $this->post(route('invoices.approval.submit', $inv))->assertSessionHasNoErrors();
        $this->post(route('invoices.approval.submit', $inv))->assertSessionHasErrors('approval');
        $this->actingAs($first)->post(route('invoices.approval.withdraw', $inv))->assertSessionHasErrors('approval');
        $this->actingAs($admin)->post(route('invoices.approval.withdraw', $inv))->assertSessionHasNoErrors();
        $this->assertFalse($this->fresh($admin, $inv)->isLocked());
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '4'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->inTenant($admin, fn () => SalesDocument::where('kind', 'commercial')->count()));
    }
}
