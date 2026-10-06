<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\SalesDocument;
use App\Support\CounterpartyLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Hərəkətlər: debit / credit with each party per currency — commercial invoice, its corrections, payments, logistics. */
class CounterpartyLedgerTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    public function test_balances_follow_documents_and_payments(): void
    {
        config(['glaust.invoice_approval_flow' => false]);
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        $deal = $this->inTenant($admin, fn () => Deal::with('counterparty', 'supplier')->find($inv->deal_id));
        [$rub, $eur, $carrier] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'RUB', 'bank_name' => 'TuranBank', 'currency' => 'RUB', 'opening_balance' => 0, 'is_active' => true]),
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 500000, 'is_active' => true]),
            Counterparty::create(['type' => 'logistics', 'entity_type' => 'legal', 'name' => 'Ritloga', 'country' => 'Poland']),
        ]);
        $pfTotal = $this->inTenant($admin, fn () => SalesDocument::where('kind', 'proforma')->firstOrFail()->grandTotal());
        $day = today()->subDay()->toDateString();
        $this->actingAs($admin);

        // before the commercial invoice nobody is in debt
        $this->assertSame([], $this->inTenant($admin, fn () => CounterpartyLedger::for($deal->counterparty)['balances']));

        $this->post(route('invoices.approval.finalize', $inv))->assertSessionHasNoErrors();   // commercial invoice issued
        $this->inTenant($admin, function () {   // corrected later: 673.14 lower
            $ci = SalesDocument::where('kind', 'commercial')->firstOrFail();
            $ci->revisionReason = 'Miqdar azaldı';
            $ci->update(['total' => round((float) $ci->total - 673.14, 2)]);
        });
        $this->post(route('deals.payments.store', $deal), ['transaction_date' => $day, 'currency' => 'RUB', 'amount' => (string) $pfTotal, 'bank_account_id' => $rub->id])->assertSessionHasNoErrors();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '50000', 'bank_account_id' => $eur->id])->assertSessionHasNoErrors();
        $this->post(route('deals.logistics-acts.store', $deal), ['counterparty_id' => $carrier->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'L-7', 'logistics_invoice_date' => $day,
            'amount' => '2750', 'currency' => 'EUR', 'payment_plan' => 'invoice', 'parts' => [['act_amount' => '2000', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => $day, 'fee_amount' => '0']]])->assertSessionHasNoErrors();

        $this->inTenant($admin, function () use ($deal, $inv, $carrier) {
            $this->assertSame(['RUB' => -673.14], CounterpartyLedger::for($deal->counterparty)['balances'], 'paid the proforma, the commercial invoice came out lower: we owe the buyer');
            $adj = (float) \App\Models\InvoiceAdjustment::firstOrFail()->amount;   // the seller invoice corrected with the commercial invoice
            $this->assertLessThan(0, $adj);
            $this->assertSame(['EUR' => round(50000 - (float) $inv->total - $adj, 2)], CounterpartyLedger::for($deal->supplier)['balances'], 'we owe the seller the rest of its corrected invoice');
            $this->assertSame(['EUR' => -750.0], CounterpartyLedger::for($carrier)['balances'], 'logistics invoice 2 750, paid 2 000');
            $buyer = CounterpartyLedger::for($deal->counterparty)['entries'];
            $correction = collect($buyer)->first(fn ($e) => str_starts_with($e['doc'], 'Düzəliş'));
            $this->assertSame(['Miqdar azaldı', 673.14], [$correction['text'], $correction['credit']], 'the correction lowers the debt');
        });

        $this->get(route('counterparties.index'))->assertOk()->assertSee('Balans')->assertSee('−'.money(673.14, 'RUB'));
        $this->get(route('counterparties.ledger', $deal->counterparty))->assertOk()->assertSee('Biz borcluyuq')->assertSee('Commercial Invoice')->assertSee('Miqdar azaldı');
        $this->get(route('counterparties.ledger', $carrier))->assertOk()->assertSee('L-7')->assertSee(money(750, 'EUR'));
        $this->get(route('counterparties.show', $carrier))->assertOk()->assertSee('Hərəkətlər');

        // totals on top, a click filters the list
        $this->inTenant($admin, function () {
            $t = CounterpartyLedger::totals();
            $this->assertSame(673.14, $t['we_owe']['RUB']);
            $this->assertGreaterThanOrEqual(2, $t['count']['we_owe']);
        });
        $this->get(route('counterparties.index'))->assertOk()->assertSee('Bizə borcludurlar')->assertSee('balance=we_owe', false);
        $this->get(route('counterparties.index', ['balance' => 'we_owe']))->assertOk()->assertSee('Ritloga');
        $this->get(route('counterparties.index', ['balance' => 'owes_us']))->assertOk()->assertDontSee('Ritloga');

        // period: what came before `from` is the opening balance, nothing after `to` counts
        $later = today()->addDay()->toDateString();
        $this->inTenant($admin, function () use ($carrier, $later) {
            $p = CounterpartyLedger::for($carrier, $later);
            $this->assertSame([[], ['EUR' => -750.0]], [$p['entries'], $p['opening']]);
            $early = CounterpartyLedger::for($carrier, null, today()->subDays(3)->toDateString());
            $this->assertSame([[], []], [$early['entries'], $early['balances']]);
        });
        $this->get(route('counterparties.ledger', [$carrier, 'from' => $later]))->assertOk()->assertSee('Əvvəlki qalıq')->assertDontSee('L-7');

        // Excel export of the statement
        $x = $this->get(route('counterparties.ledger', [$carrier, 'from' => $day, 'format' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
    }
}
