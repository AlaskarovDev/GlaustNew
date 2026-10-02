<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Project;
use App\Models\Shipment;
use App\Models\Task;
use App\Models\User;
use App\Http\Controllers\ReportController;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every screen with a full demo dataset: catches Blade/runtime errors that
 * unit tests never reach. Exports are downloaded too.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeCbar();
        $this->seedPlans();
        $this->artisan('glaust:demo', ['--companies' => 1, '--password' => 'Secret123', '--days' => 40])->assertSuccessful();
        $this->admin = User::where('email', 'admin@xezer-konsaltinq.demo')->firstOrFail();
    }

    private function ids(): array
    {
        return app(Tenant::class)->runAs($this->admin->company, fn () => [
            'project' => Project::first(),
            'task' => Task::first(),
            'counterparty' => Counterparty::first(),
            'contract' => Contract::where('status', '!=', 'draft')->first(),
            'tx' => BankTransaction::first(),
            'transfer' => BankTransaction::whereNotNull('transfer_group')->first(),
            'shipment' => Shipment::first(),
        ]);
    }

    public function test_demo_data_is_complete(): void
    {
        app(Tenant::class)->runAs($this->admin->company, function () {
            $this->assertSame(5, User::forTenant()->count());
            $this->assertGreaterThanOrEqual(20, Counterparty::count());
            $this->assertSame(10, Project::count());
            $this->assertSame(15, Contract::count());
            $this->assertGreaterThanOrEqual(150, BankTransaction::count());
            $this->assertSame(30, Shipment::count());
        });
    }

    public function test_every_page_renders(): void
    {
        $m = $this->ids();
        $this->actingAs($this->admin);

        $urls = [
            route('dashboard'), route('my-work'), route('currency.index'), route('currency.index', ['date' => now()->subDays(3)->format('Y-m-d'), 'code' => 'RUB', 'days' => 90]),
            route('profile.edit'), route('profile.edit', ['tab' => 'security']),
            route('projects.index'), route('projects.index', ['view' => 'list', 'q' => 'a', 'sort' => 'name']), route('projects.create'),
            route('projects.show', $m['project']), route('projects.show', [$m['project'], 'tab' => 'board']),
            route('projects.show', [$m['project'], 'tab' => 'finance']), route('projects.show', [$m['project'], 'tab' => 'files']), route('projects.edit', $m['project']),
            route('tasks.index'), route('tasks.index', ['view' => 'list']), route('tasks.index', ['view' => 'calendar']), route('tasks.index', ['view' => 'gantt']),
            route('tasks.index', ['due' => 'overdue', 'assignee_id' => 'me']), route('tasks.create'), route('tasks.show', $m['task']), route('tasks.edit', $m['task']),
            route('counterparties.index'), route('counterparties.index', ['type' => 'supplier']), route('counterparties.create', ['type' => 'supplier']),
            route('counterparties.show', $m['counterparty']), route('counterparties.edit', $m['counterparty']),
            route('contracts.index'), route('contracts.index', ['ending' => '30', 'status' => 'open']), route('contracts.create'),
            route('contracts.create', ['counterparty_id' => $m['counterparty']->id]), route('contracts.show', $m['contract']), route('contracts.edit', $m['contract']),
            route('contracts.create', ['parent_id' => $m['contract']->id]),
            route('bank.transactions.index'), route('bank.transactions.index', ['direction' => 'in', 'from' => now()->subMonth()->format('Y-m-d')]),
            route('bank.transactions.create'), route('bank.transactions.create', ['direction' => 'out']), route('bank.transactions.create', ['mode' => 'transfer']),
            route('bank.transactions.show', $m['tx']), route('bank.transactions.show', $m['transfer']), route('bank.transactions.edit', $m['tx']),
            route('bank.accounts.index'), route('bank.accounts.create'),
            route('shipments.index'), route('shipments.index', ['status' => 'delayed']), route('shipments.create'), route('shipments.show', $m['shipment']), route('shipments.edit', $m['shipment']),
            route('reports.index'), route('imports.index'), route('imports.index', ['type' => 'bank_transactions']),
            route('settings.index'), route('settings.company'), route('settings.general'), route('settings.mail'), route('settings.categories'),
            route('settings.users.index'), route('settings.users.create'), route('settings.users.edit', $this->admin->id),
            route('settings.roles.index'), route('settings.roles.create'),
            route('settings.logs.logins'), route('settings.logs.sessions'), route('settings.logs.audit'), route('settings.logs.mail'),
            route('ajax.ticker'), route('ajax.my-day'), route('ajax.search', ['q' => 'MQ']), route('ajax.lookup', 'counterparties'),
            route('ajax.lookup', ['counterparties', 'role' => 'supplier', 'q' => 'a']), route('ajax.lookup', 'contracts'), route('ajax.lookup', 'projects'), route('ajax.lookup', 'users'),
            route('ajax.rate', ['currency' => 'USD', 'date' => now()->subDays(2)->format('Y-m-d')]),
        ];
        foreach (ReportController::REPORTS as $r) {
            $urls[] = route('reports.show', $r::key());
        }
        $urls[] = route('reports.show', ['counterparties', 'counterparty_id' => $m['counterparty']->id]);
        $urls[] = route('reports.show', ['income-expense', 'group' => 'category']);

        foreach ($urls as $url) {
            $res = $this->get($url);
            $this->assertSame(200, $res->getStatusCode(), "GET {$url} -> {$res->getStatusCode()}\n"
                .($res->exception ? get_class($res->exception).': '.$res->exception->getMessage().' @ '.$res->exception->getFile().':'.$res->exception->getLine() : ''));
        }
    }

    public function test_exports_download(): void
    {
        $m = $this->ids();
        $this->actingAs($this->admin);
        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        $exports = ['projects.export', 'tasks.export', 'counterparties.export', 'contracts.export', 'bank.transactions.export', 'shipments.export'];
        foreach ($exports as $route) {
            foreach (['xlsx' => $xlsx, 'pdf' => 'application/pdf'] as $format => $type) {
                $res = $this->get(route($route, ['format' => $format]));
                $res->assertOk();
                $this->assertStringContainsString($type, $res->headers->get('Content-Type'), "$route $format");
                $body = $res->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $res->streamedContent() : $res->getContent();
                $this->assertGreaterThan(1000, strlen($body), "$route $format is empty");
            }
        }
        foreach (ReportController::REPORTS as $r) {
            $this->get(route('reports.export', [$r::key(), 'format' => 'xlsx']))->assertOk();
            $this->get(route('reports.export', [$r::key(), 'format' => 'pdf']))->assertOk();
        }
        $pdf = $this->get(route('contracts.pdf', $m['contract']));
        $pdf->assertOk();
        $this->assertStringContainsString('DejaVuSans', $pdf->getContent(), 'PDF embeds a Unicode font for ə/ğ/ş');

        $this->get(route('settings.logs.logins', ['format' => 'xlsx']))->assertOk();
        $this->get(route('settings.logs.audit', ['format' => 'pdf']))->assertOk();
        foreach (\App\Imports\ImportRunner::TYPES as $t) {
            $this->get(route('imports.template', $t::type()))->assertOk();
        }
    }

    public function test_dashboard_shows_charts_kpis_and_shortcuts(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Gəlir və xərc')
            ->assertSee('Layihələrin statusu')
            ->assertSee('Yeni müqavilə')
            ->assertSee('x-chart', false)
            ->assertSee('x-countup', false)
            ->assertSee('glaust-shortcuts', false);
    }

    public function test_super_admin_area(): void
    {
        $owner = new User(['name' => 'Owner', 'email' => 'owner@glaust.az', 'password' => 'Secret123', 'is_active' => true]);
        $owner->is_super_admin = true;
        $owner->save();
        $company = $this->admin->company;
        $plan = \App\Models\Plan::first();

        $this->actingAs($owner);
        foreach ([route('admin.dashboard'), route('admin.companies.index'), route('admin.companies.show', $company), route('admin.plans.index'), route('admin.logs')] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
        $this->put(route('admin.companies.update', $company), ['plan_id' => $plan->id, 'subscription_status' => 'suspended'])->assertRedirect();

        // A suspended company only reaches the subscription page.
        $this->actingAs($this->admin->fresh())->get(route('projects.index'))->assertRedirect(route('subscription.expired'));
        $this->get(route('subscription.expired'))->assertOk()->assertSee('Abunə');

        // Tenants never reach the platform area.
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertNotFound();
    }
}
