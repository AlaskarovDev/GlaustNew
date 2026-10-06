<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SupplierPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CalculatedInvoice;
use Tests\TestCase;

/** Deleting a Trade / project with everything in it: confirmed by its code, money on the accounts undone. */
class DealDeletionTest extends TestCase
{
    use CalculatedInvoice, RefreshDatabase;

    private function busyDeal(): array
    {
        [$admin, $inv] = $this->calculated();
        $this->applyRub($inv);
        $deal = $this->inTenant($admin, fn () => Deal::find($inv->deal_id));
        [$eur, $rub, $carrier] = $this->inTenant($admin, fn () => [
            BankAccount::create(['name' => 'EUR', 'bank_name' => 'ABB', 'currency' => 'EUR', 'opening_balance' => 300000, 'is_active' => true]),
            BankAccount::create(['name' => 'RUB', 'bank_name' => 'TuranBank', 'currency' => 'RUB', 'opening_balance' => 0, 'is_active' => true]),
            Counterparty::create(['type' => 'logistics', 'entity_type' => 'legal', 'name' => 'Ritloga', 'country' => 'Lithuania']),
        ]);
        $day = today()->subDay()->toDateString();
        $this->actingAs($admin);
        $this->post(route('deals.payments.store', $deal), ['transaction_date' => $day, 'currency' => 'RUB', 'amount' => '1000000', 'bank_account_id' => $rub->id])->assertSessionHasNoErrors();
        $this->post(route('deals.payments.store', $deal), ['direction' => 'out', 'payment_date' => $day, 'currency' => 'EUR', 'amount' => '50000', 'bank_account_id' => $eur->id])->assertSessionHasNoErrors();
        $this->post(route('deals.logistics-acts.store', $deal), ['counterparty_id' => $carrier->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'L-1', 'logistics_invoice_date' => $day,
            'amount' => '2750', 'currency' => 'EUR', 'payment_plan' => 'invoice', 'parts' => [['act_amount' => '2750', 'currency' => 'EUR', 'bank_account_id' => $eur->id, 'payment_date' => $day]]])->assertSessionHasNoErrors();

        return [$admin, $deal, $eur, $rub];
    }

    public function test_trade_with_money_is_deleted_only_with_its_code_and_everything_is_undone(): void
    {
        [$admin, $deal, $eur, $rub] = $this->busyDeal();
        $this->get(route('deals.show', $deal))->assertOk()->assertSee('Birdəfəlik sil')->assertSee('Satıcı fakturaları');
        $this->assertLessThan(300000, $this->inTenant($admin, fn () => BankAccount::find($eur->id)->balance()));

        $this->delete(route('deals.destroy', $deal))->assertSessionHas('error');
        $this->delete(route('deals.destroy', $deal), ['confirm_code' => 'WRONG'])->assertSessionHas('error');
        $this->assertNotNull($this->inTenant($admin, fn () => Deal::find($deal->id)));

        $this->delete(route('deals.destroy', $deal), ['confirm_code' => $deal->code])->assertRedirect(route('projects.show', [$deal->project_id, 'tab' => 'deals']));
        $this->inTenant($admin, function () use ($deal, $eur, $rub) {
            $this->assertNull(Deal::find($deal->id));
            $this->assertSame(0, Invoice::where('deal_id', $deal->id)->count());
            $this->assertSame(0, SalesDocument::where('deal_id', $deal->id)->count());
            $this->assertSame(0, SupplierPayment::count());
            $this->assertSame(0, LogisticsAct::count());
            $this->assertSame(0, Expense::count(), 'bank fees removed');
            $this->assertSame(0, BankTransaction::count());
            $this->assertSame(300000.0, BankAccount::find($eur->id)->balance(), 'payments and fees undone');
            $this->assertSame(0.0, BankAccount::find($rub->id)->balance(), 'incoming payment undone');
        });
    }

    public function test_project_goes_with_its_trades(): void
    {
        [$admin, $deal, $eur] = $this->busyDeal();
        $project = $this->inTenant($admin, fn () => Project::find($deal->project_id));
        $this->get(route('projects.show', $project))->assertOk()->assertSee('Birdəfəlik sil');
        $this->delete(route('projects.destroy', $project))->assertSessionHas('error');
        $this->delete(route('projects.destroy', $project), ['confirm_code' => $project->code])->assertRedirect(route('projects.index'));
        $this->inTenant($admin, function () use ($project, $deal, $eur) {
            $this->assertNull(Project::find($project->id));
            $this->assertNull(Deal::find($deal->id));
            $this->assertSame(300000.0, BankAccount::find($eur->id)->balance());
        });
    }
}
