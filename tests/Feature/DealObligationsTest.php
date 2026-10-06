<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Deal;
use App\Support\DealObligations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Maliyyə öhdəlikləri of a deal: money and goods owed in each direction, per currency. */
class DealObligationsTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_obligations_follow_documents_and_payments(): void
    {
        [$admin, $inv] = $this->calculated();
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        $ob = fn () => $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)));

        // Before the proforma: no buyer bill, no logistics obligation yet; the seller's invoice is owed.
        $this->assertSame([], $ob()['buyer']['due']);
        $this->assertSame([], $ob()['logistics']['due']);
        $this->assertSame(['EUR' => 191922.0], $ob()['seller']['due']);

        $this->applyRub($inv);
        $o = $ob();
        $this->assertSame(['RUB' => 19800178.0], $o['buyer']['due'], 'buyer owes the proforma');
        $this->assertSame(['EUR' => 10200.0], $o['logistics']['due'], 'logistics owed once the proforma is ready, in its own currency');
        $this->assertSame(['EUR' => 202122.0], $o['payable']);

        [$rub, $eur] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'RUB', 'bank_name' => 'Kapital Bank', 'currency' => 'RUB', 'is_active' => true]),
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'Kapital Bank', 'currency' => 'EUR', 'opening_balance' => 500000, 'is_active' => true]),
        ]);
        $day = today()->subDay()->toDateString();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'in', 'transaction_date' => $day, 'currency' => 'RUB', 'amount' => '5 000 000', 'bank_account_id' => $rub->id])->assertSessionHasNoErrors();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '100 000', 'bank_account_id' => $eur->id])->assertSessionHasNoErrors();

        $out = $this->inTenant($admin, fn () => BankTransaction::where('direction', 'out')->where('counterparty_id', $deal->supplier_id)->firstOrFail());
        $this->assertSame($deal->supplier_id, $out->counterparty_id, 'paid to the seller automatically');
        $this->assertSame($deal->purchase_contract_id, $out->contract_id);

        $o = $ob();
        $this->assertSame(['RUB' => 14800178.0], $o['buyer']['due']);
        $this->assertSame(['RUB' => 5000000.0], $o['buyer']['goods'], 'we owe the buyer goods worth what it paid');
        $this->assertSame(['EUR' => 91922.0], $o['seller']['due']);
        $this->assertSame(['EUR' => 100000.0], $o['seller']['goods'], 'the seller owes us goods worth what we paid');
        $this->assertSame(['EUR' => 102122.0], $o['payable']);
        $this->assertSame(['RUB' => 14800178.0], $o['receivable']);

        $this->get(route('deals.show', $deal))->assertOk()->assertSee('Maliyyə öhdəlikləri')
            ->assertSee(money(102122, 'EUR'))->assertSee(money(14800178, 'RUB'))->assertSee('dəyərində məhsul göndərməliyik');
        $this->get(route('deals.show', [$deal, 'tab' => 'income']))->assertOk()->assertSee('Satıcıya ödənişlər')->assertSee(money(100000, 'EUR'));

        // Öhdəliklərim: all deals added up, broken down by project and deal, linking to the project's deals tab.
        $project = $this->inTenant($admin, fn () => Deal::find($deal->id)->project);
        $this->get(route('obligations.index'))->assertOk()
            ->assertSee('Ödəməli olduğum')->assertSee('Məhsulla təmin etməli olduğum')->assertSee('Mənə gəlməli ödənişlər')
            ->assertSee(money(102122, 'EUR'))->assertSee(money(14800178, 'RUB'))->assertSee(money(5000000, 'RUB'))
            ->assertSee($project->name)->assertSee('#deal-'.$deal->id, false);
        $this->get(route('projects.show', [$project, 'tab' => 'deals']))->assertOk()->assertSee('id="deal-'.$deal->id.'"', false);
    }

    /** The commercial invoice is the final bill: a buyer who paid the proforma is owed the difference; overpaying the seller is a claim on it. */
    public function test_overpayments_after_the_commercial_invoice(): void
    {
        config(['glaust.invoice_approval_flow' => false]);
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        [$rub, $eur] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'RUB', 'bank_name' => 'TuranBank', 'currency' => 'RUB', 'opening_balance' => 0, 'is_active' => true]),
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 500000, 'is_active' => true]),
        ]);
        $pf = $this->inTenant($admin, fn () => \App\Models\SalesDocument::where('kind', 'proforma')->firstOrFail());
        $day = today()->subDay()->toDateString();
        $this->actingAs($admin);
        // the buyer pays the proforma in full; we pay the seller 100 EUR more than its invoice
        $this->post(route('deals.payments.store', $deal), ['transaction_date' => $day, 'currency' => 'RUB', 'amount' => (string) $pf->grandTotal(), 'bank_account_id' => $rub->id])->assertSessionHasNoErrors();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => (string) ((float) $inv->total + 100), 'bank_account_id' => $eur->id])->assertSessionHasNoErrors();

        // the commercial invoice comes out 673.14 lower than the proforma
        $this->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();
        $this->inTenant($admin, function () {
            $ci = \App\Models\SalesDocument::where('kind', 'commercial')->firstOrFail();
            $lines = $ci->lines;
            $lines[0]['total'] = round($lines[0]['total'] - 673.14, 2);
            $ci->update(['lines' => $lines, 'total' => round((float) $ci->total - 673.14, 2)]);
        });

        $ob = $this->inTenant($admin, fn () => DealObligations::for(Deal::find($deal->id)));
        $this->assertTrue($ob['buyer']['final']);
        $this->assertSame(['RUB' => 673.14], $ob['buyer']['overpaid'], 'we owe the buyer the difference');
        $this->assertSame([], $ob['buyer']['due']);
        $this->assertSame(['EUR' => 100.0], $ob['seller']['overpaid'], 'the seller owes us');
        $this->assertSame(673.14, $ob['payable']['RUB']);
        $this->assertSame(100.0, $ob['receivable']['EUR']);

        $this->get(route('deals.show', $deal))->assertOk()->assertSee('Alıcıya qaytarmalıyıq')->assertSee('Satıcı bizə borcludur')->assertSee(money(673.14, 'RUB'));
        $this->get(route('obligations.index'))->assertOk()->assertSee('alıcıya qaytarılmalı')->assertSee('satıcı qaytarmalıdır');
    }
}
