<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ShipmentCost;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Cbar\CurrencyRates;
use App\Services\NumberGenerator;
use App\Tables\ProjectTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $table = new ProjectTable($request);
        $view = $request->query('view') === 'list' ? 'list' : 'grid';
        $counts = Project::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('projects.index', ['table' => $table, 'items' => $table->paginate($view === 'grid' ? 24 : 25), 'view' => $view, 'counts' => $counts]);
    }

    public function export(Request $request): Response
    {
        return (new ProjectTable($request))->export((string) $request->query('format', 'xlsx'));
    }

    public function create(NumberGenerator $numbers, Request $request): View
    {
        $this->authorize('projects.create');

        return view('projects.form', ['project' => new Project([
            'code' => $numbers->next('project'), 'status' => 'planned', 'priority' => 'medium', 'currency' => 'AZN',
            'start_date' => today(), 'manager_id' => $request->user()->id,
        ]), 'memberIds' => [$request->user()->id]]);
    }

    public function store(Request $request, NumberGenerator $numbers): RedirectResponse
    {
        $this->authorize('projects.create');
        if (blank($request->input('code'))) {
            $request->merge(['code' => $numbers->next('project')]);
        }
        $project = DB::transaction(function () use ($request) {
            [$data, $members] = $this->validated($request);
            $project = Project::create($data);
            $project->members()->sync($members);

            return $project;
        });

        return redirect()->route('projects.show', $project)->with('success', "Layihə {$project->code} yaradıldı.");
    }

    public function show(Request $request, Project $project, CurrencyRates $rates): View
    {
        $project->load(['counterparty', 'supplier', 'saleContract.payments', 'saleContract.counterparty', 'purchaseContract.payments', 'purchaseContract.counterparty', 'manager', 'members', 'milestones' => fn ($q) => $q->withCount(['tasks', 'tasks as done_tasks_count' => fn ($t) => $t->where('status', 'done')]), 'attachments.uploader']);
        $tab = in_array($request->query('tab'), ['overview', 'deals', 'board', 'finance', 'files'], true) ? $request->query('tab') : 'overview';

        $tasks = $project->tasks()->with('assignee:id,name,email')
            ->withCount(['checklist', 'checklist as checklist_done_count' => fn ($q) => $q->where('is_done', true), 'comments'])
            ->orderBy('position')->orderBy('due_date')->get();
        $stats = [
            'total' => $tasks->count(),
            'done' => $tasks->where('status', 'done')->count(),
            'overdue' => $tasks->filter->isOverdue()->count(),
            'hours' => (float) $project->tasks()->join('time_entries', 'time_entries.task_id', '=', 'tasks.id')->sum('time_entries.minutes') / 60,
        ];

        $finance = null;
        if ($request->user()->can('bank.view') || $request->user()->can('logistics.view')) {
            $transactions = $request->user()->can('bank.view') ? BankTransaction::with('account', 'counterparty')->where('project_id', $project->id)->latest('transaction_date')->get() : collect();
            $costs = $request->user()->can('logistics.view') ? ShipmentCost::with('shipment')->whereHas('shipment', fn ($q) => $q->where('project_id', $project->id))->get() : collect();
            $budgetRate = $rates->tryRate($project->currency, $rates->today());
            $finance = [
                'transactions' => $transactions,
                'costs' => $costs,
                'income' => (float) $transactions->where('direction', 'in')->where('kind', 'regular')->sum('amount_azn'),
                'expense' => (float) $transactions->where('direction', 'out')->where('kind', 'regular')->sum('amount_azn') + (float) $costs->sum('amount_azn'),
                'budget_azn' => $budgetRate !== null ? round((float) $project->budget * $budgetRate, 2) : null,
            ];
        }

        $deals = $project->deals()->with(['counterparty:id,name', 'supplier:id,name', 'saleContract:id,number', 'purchaseContract:id,number'])
            ->withCount('invoices')->withSum(['invoices as supplier_total_azn' => fn ($q) => $q->where('type', 'supplier')], 'total_azn')->get();

        // Money actually moved under each side's contract (bank, AZN).
        $settled = [];
        foreach (['sale' => $project->saleContract, 'purchase' => $project->purchaseContract] as $side => $c) {
            $settled[$side] = $c ? $c->settledAzn() : 0.0;
        }

        $history = AuditLog::with('user')->where('auditable_type', 'project')->where('auditable_id', $project->id)->latest('created_at')->limit(20)->get();

        return view('projects.show', compact('project', 'tab', 'tasks', 'stats', 'finance', 'history', 'settled', 'deals'));
    }

    public function edit(Project $project): View
    {
        $this->authorize('projects.update');
        $project->load('counterparty', 'supplier', 'saleContract', 'purchaseContract');

        return view('projects.form', ['project' => $project, 'memberIds' => $project->members()->pluck('users.id')->all()]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('projects.update');
        DB::transaction(function () use ($request, $project) {
            [$data, $members] = $this->validated($request, $project);
            $project->update($data);
            $project->members()->sync($members);
        });

        return redirect()->route('projects.show', $project)->with('success', 'Layihə yeniləndi.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('projects.delete');
        $project->delete();

        return redirect()->route('projects.index')->with('success', "Layihə {$project->code} silindi.");
    }

    public function storeMilestone(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('projects.update');
        $data = $request->validate(['name' => ['required', 'string', 'max:190'], 'due_date' => ['nullable', 'date']], [], ['name' => 'Mərhələnin adı']);
        $project->milestones()->create($data);

        return back()->with('success', 'Mərhələ əlavə edildi.');
    }

    public function updateMilestone(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        $this->authorize('projects.update');
        abort_unless($milestone->project_id === $project->id, 404);
        $milestone->update(['completed_at' => $milestone->completed_at ? null : now()]);

        return back();
    }

    public function destroyMilestone(Project $project, Milestone $milestone): RedirectResponse
    {
        $this->authorize('projects.update');
        abort_unless($milestone->project_id === $project->id, 404);
        $milestone->delete();

        return back()->with('success', 'Mərhələ silindi.');
    }

    /** @return array{0: array, 1: int[]} */
    private function validated(Request $request, ?Project $project = null): array
    {
        $request->merge(['budget' => parse_number($request->input('budget')) ?? 0]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('projects', 'code')->where('company_id', tenant()->id)->ignore($project?->id)],
            'name' => ['required', 'string', 'max:190'],
            'counterparty_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'supplier_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'sale_contract_id' => ['nullable', 'integer', TenantExists::in('contracts')],
            'purchase_contract_id' => ['nullable', 'integer', TenantExists::in('contracts')],
            'manager_id' => ['nullable', 'integer', TenantExists::plain('users')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', Rule::in(array_keys(config('glaust.statuses.project')))],
            'priority' => ['required', Rule::in(array_keys(config('glaust.statuses.priority')))],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'description' => ['nullable', 'string', 'max:10000'],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', TenantExists::plain('users')],
        ], ['code.unique' => 'Bu kodla layihə artıq var.'], [
            'code' => 'Layihə kodu', 'manager_id' => 'Menecer', 'members.*' => 'Komanda üzvü',
            'counterparty_id' => 'Məhsulu alan tərəf', 'supplier_id' => 'Məhsulu satan tərəf',
            'sale_contract_id' => 'Alan tərəflə müqavilə', 'purchase_contract_id' => 'Satan tərəflə müqavilə',
        ]);

        $data = \App\Support\ContractSides::check($data);

        $members = array_map('intval', $data['members'] ?? []);
        if ($data['manager_id'] ?? null) {
            $members[] = (int) $data['manager_id'];
        }
        unset($data['members']);

        return [$data, array_values(array_unique($members))];
    }

    public static function teamOptions(): array
    {
        return User::forTenant()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
