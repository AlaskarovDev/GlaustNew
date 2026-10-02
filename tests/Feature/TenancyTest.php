<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Project;
use App\Models\Shipment;
use App\Models\Task;
use App\Models\User;
use App\Services\BankLedger;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Company A must never see, open, change or link company B's data. */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $a;

    private User $b;

    private array $bData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeCbar();
        Storage::fake('local');
        $this->a = $this->makeCompany('Alfa MMC', 'a@alfa.az');
        $this->b = $this->makeCompany('Beta MMC', 'b@beta.az');

        $this->bData = $this->inTenant($this->b, function () {
            $cp = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'Beta Müştəri', 'voen' => '9999999991', 'country' => 'Azərbaycan']);
            $project = Project::create(['code' => 'B-1', 'name' => 'Beta layihə', 'status' => 'active', 'priority' => 'medium', 'currency' => 'AZN']);
            $contract = Contract::create(['number' => 'B-MQ-1', 'contract_date' => today(), 'counterparty_id' => $cp->id, 'kind' => 'sale', 'subject' => 'Gizli', 'amount' => 100, 'currency' => 'AZN', 'status' => 'active']);
            $account = BankAccount::create(['name' => 'Beta hesab', 'bank_name' => 'X', 'currency' => 'AZN', 'opening_balance' => 0]);
            $tx = app(BankLedger::class)->record($account, ['direction' => 'in', 'transaction_date' => today()->toDateString(), 'amount' => 50]);
            $task = Task::create(['title' => 'Beta tapşırıq', 'status' => 'todo', 'priority' => 'low', 'project_id' => $project->id]);
            $shipment = Shipment::create(['number' => 'B-Y-1', 'direction' => 'import', 'origin' => 'X', 'destination' => 'Y', 'transport_mode' => 'road', 'status' => 'planned']);
            $file = $contract->attachments()->create(['original_name' => 'gizli.pdf', 'path' => 'attachments/x.pdf', 'size' => 10]);
            Storage::disk('local')->put('attachments/x.pdf', 'secret');

            return compact('cp', 'project', 'contract', 'account', 'tx', 'task', 'shipment', 'file');
        });
    }

    public function test_other_company_records_are_404(): void
    {
        $d = $this->bData;
        $this->actingAs($this->a);
        foreach ([
            route('counterparties.show', $d['cp']->id), route('counterparties.edit', $d['cp']->id),
            route('projects.show', $d['project']->id), route('contracts.show', $d['contract']->id), route('contracts.pdf', $d['contract']->id),
            route('bank.transactions.show', $d['tx']->id), route('bank.accounts.edit', $d['account']->id),
            route('tasks.show', $d['task']->id), route('shipments.show', $d['shipment']->id), route('attachments.download', $d['file']->id),
            route('settings.users.edit', $this->b->id),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->put(route('projects.update', $d['project']->id), ['name' => 'hack'])->assertNotFound();
        $this->delete(route('contracts.destroy', $d['contract']->id))->assertNotFound();
        $this->post(route('ajax.tasks.complete', $d['task']->id))->assertNotFound();
        $this->post(route('tasks.move', $d['task']->id), ['status' => 'done'])->assertNotFound();
        $this->assertSame('todo', $this->inTenant($this->b, fn () => $d['task']->fresh()->status));
    }

    public function test_lists_search_and_lookups_never_leak(): void
    {
        $this->actingAs($this->a);
        $this->get(route('counterparties.index'))->assertOk()->assertDontSee('Beta Müştəri');
        $this->get(route('contracts.index'))->assertOk()->assertDontSee('B-MQ-1');
        $this->getJson(route('ajax.search', ['q' => 'Beta']))->assertOk()->assertJsonCount(0, 'results');
        $this->getJson(route('ajax.lookup', ['counterparties', 'q' => 'Beta']))->assertJsonCount(0, 'results');
        $this->getJson(route('ajax.lookup', ['users']))->assertJsonMissing(['label' => $this->b->name]);
    }

    public function test_cannot_link_other_company_ids_in_forms(): void
    {
        $d = $this->bData;
        $this->actingAs($this->a);

        // Contract with B's counterparty
        $this->post(route('contracts.store'), [
            'number' => 'A-1', 'contract_date' => today()->toDateString(), 'counterparty_id' => $d['cp']->id, 'kind' => 'sale',
            'subject' => 'x', 'amount' => '10', 'currency' => 'AZN', 'status' => 'draft',
        ])->assertSessionHasErrors('counterparty_id');

        // Bank transaction into B's account
        $this->post(route('bank.transactions.store'), ['mode' => 'regular', 'bank_account_id' => $d['account']->id, 'direction' => 'in', 'transaction_date' => today()->toDateString(), 'amount' => '5'])
            ->assertSessionHasErrors('bank_account_id');

        // Attachment onto B's contract
        $this->post(route('attachments.store'), ['attachable_type' => 'contract', 'attachable_id' => $d['contract']->id, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertNotFound();

        $this->assertSame(0, $this->inTenant($this->a, fn () => Contract::count() + BankTransaction::count()));
    }

    public function test_scope_is_fail_closed_without_a_tenant(): void
    {
        app(Tenant::class)->set(null);
        $this->assertSame(0, Counterparty::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Attachment::count());
        $this->assertGreaterThan(0, app(Tenant::class)->withoutTenant(fn () => Counterparty::count()));
    }
}
