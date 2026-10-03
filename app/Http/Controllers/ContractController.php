<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Counterparty;
use App\Rules\TenantExists;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Services\NumberGenerator;
use App\Support\Export\PdfExporter;
use App\Tables\ContractTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ContractController extends Controller
{
    public function __construct(private CurrencyRates $rates, private NumberGenerator $numbers) {}

    public function index(Request $request): View
    {
        $table = new ContractTable($request);
        $items = $table->paginate();
        $summary = Contract::whereIn('status', ['signed', 'active'])
            ->selectRaw("kind, COUNT(*) as n, SUM(amount_azn) as s")->groupBy('kind')->get()->keyBy('kind');
        $ending = Contract::whereIn('status', ['signed', 'active'])->whereBetween('end_date', [today()->toDateString(), today()->addDays(30)->toDateString()])->count();

        return view('contracts.index', compact('table', 'items', 'summary', 'ending'));
    }

    public function export(Request $request): Response
    {
        return (new ContractTable($request))->export((string) $request->query('format', 'xlsx'));
    }

    public function create(Request $request): View
    {
        $this->authorize('contracts.create');
        $contract = new Contract([
            'contract_date' => today(), 'start_date' => today(), 'currency' => 'AZN', 'status' => 'draft',
            'kind' => 'sale', 'responsible_id' => $request->user()->id,
            'number' => $this->numbers->next('contract'),
        ]);
        if ($cp = Counterparty::find($request->integer('counterparty_id'))) {
            $contract->counterparty_id = $cp->id;
            $contract->setRelation('counterparty', $cp);
            $contract->kind = $cp->isCustomer() ? 'sale' : 'purchase';
        }
        // Opened from a project's buyer / supplier section.
        if ($project = \App\Models\Project::find($request->integer('project_id'))) {
            $contract->project_id = $project->id;
            $contract->setRelation('project', $project);
            if (in_array($request->query('kind'), ['sale', 'purchase'], true)) {
                $contract->kind = $request->query('kind');
                $contract->subject = ($contract->kind === 'sale' ? 'Məhsulun satışı — ' : 'Məhsulun alışı — ').$project->name;
            }
        }
        if ($parent = Contract::find($request->integer('parent_id'))) {
            $contract->fill(['parent_id' => $parent->id, 'counterparty_id' => $parent->counterparty_id, 'kind' => $parent->kind, 'currency' => $parent->currency, 'project_id' => $parent->project_id]);
            $contract->setRelation('counterparty', $parent->counterparty);
            $contract->subject = 'Əlavə razılaşma — '.$parent->number;
        }

        return view('contracts.form', ['contract' => $contract, 'payments' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('contracts.create');
        $contract = DB::transaction(function () use ($request) {
            [$data, $payments] = $this->validated($request);
            $contract = Contract::create($data);
            $this->syncPayments($contract, $payments);
            $this->fillProjectSlot($contract);

            return $contract;
        });

        return redirect()->route('contracts.show', $contract)->with('success', "Müqavilə {$contract->number} yaradıldı.");
    }

    public function show(Contract $contract): View
    {
        $contract->load(['counterparty', 'project', 'responsible', 'parent', 'amendments.counterparty', 'payments', 'attachments.uploader']);
        $transactions = auth()->user()->can('bank.view')
            ? $contract->transactions()->with('account')->latest('transaction_date')->get()
            : collect();
        $settled = $transactions->where('direction', $contract->kind === 'sale' ? 'in' : 'out')->where('kind', 'regular')->sum('amount_azn');
        $history = AuditLog::with('user')->where('auditable_type', 'contract')->where('auditable_id', $contract->id)->latest('created_at')->limit(20)->get();

        return view('contracts.show', compact('contract', 'transactions', 'settled', 'history'));
    }

    public function edit(Contract $contract): View
    {
        $this->authorize('contracts.update');
        $contract->load('counterparty', 'project', 'responsible');

        return view('contracts.form', [
            'contract' => $contract,
            'payments' => $contract->payments->map(fn ($p) => ['due_date' => $p->due_date->format('Y-m-d'), 'amount' => (string) $p->amount, 'note' => $p->note, 'paid' => (bool) $p->paid_at])->all(),
        ]);
    }

    public function update(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('contracts.update');
        DB::transaction(function () use ($request, $contract) {
            [$data, $payments] = $this->validated($request, $contract);
            $contract->update($data);
            $this->syncPayments($contract, $payments);
            $this->fillProjectSlot($contract);
        });

        return redirect()->route('contracts.show', $contract)->with('success', 'Müqavilə yeniləndi.');
    }

    public function destroy(Contract $contract): RedirectResponse
    {
        $this->authorize('contracts.delete');
        if ($contract->transactions()->exists()) {
            return back()->with('error', 'Bu müqaviləyə bağlı bank əməliyyatları var. Müqaviləni silmək əvəzinə statusunu «Ləğv» edin.');
        }
        $contract->delete();

        return redirect()->route('contracts.index')->with('success', "Müqavilə {$contract->number} silindi.");
    }

    public function togglePayment(Contract $contract, ContractPayment $payment): RedirectResponse
    {
        abort_unless($payment->contract_id === $contract->id, 404);
        $payment->update(['paid_at' => $payment->paid_at ? null : now()]);

        return back()->with('success', $payment->paid_at ? 'Ödəniş ödənilmiş kimi qeyd edildi.' : 'Ödəniş gözləmədə.');
    }

    public function pdf(Contract $contract, PdfExporter $pdf): Response
    {
        $contract->load(['counterparty.contacts', 'project', 'responsible', 'payments', 'amendments']);

        return $pdf->render('contracts.pdf', ['contract' => $contract, 'title' => 'Müqavilə '.$contract->number], 'muqavile-'.$contract->number.'.pdf', false, true);
    }

    /**
     * A contract tied to a project fills that project's empty buyer (sale) or supplier
     * (purchase) slot, so creating it from the project page links it in one step.
     */
    private function fillProjectSlot(Contract $contract): void
    {
        $project = $contract->project_id ? \App\Models\Project::find($contract->project_id) : null;
        if (! $project) {
            return;
        }
        [$slot, $party] = $contract->kind === 'sale' ? ['sale_contract_id', 'counterparty_id'] : ['purchase_contract_id', 'supplier_id'];
        if ($project->{$slot} || ($project->{$party} && $project->{$party} !== $contract->counterparty_id)) {
            return; // slot taken, or the project names another party on that side
        }
        $project->update([$slot => $contract->id, $party => $contract->counterparty_id]);
    }

    /** @return array{0: array, 1: array} */
    private function validated(Request $request, ?Contract $contract = null): array
    {
        if (blank($request->input('number')) && ! $contract) {
            $request->merge(['number' => $this->numbers->next('contract')]);
        }
        $request->merge(['amount' => parse_number($request->input('amount'))]);
        $payments = collect((array) $request->input('payments'))
            ->filter(fn ($p) => filled($p['due_date'] ?? null) || filled($p['amount'] ?? null))
            ->map(fn ($p) => array_merge($p, ['amount' => parse_number($p['amount'] ?? null)]))
            ->values()->all();
        $request->merge(['payments' => $payments]);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:40', Rule::unique('contracts', 'number')->where('company_id', tenant()->id)->ignore($contract?->id)],
            'contract_date' => ['required', 'date'],
            'counterparty_id' => ['required', 'integer', TenantExists::in('counterparties')],
            'kind' => ['required', Rule::in(['sale', 'purchase'])],
            'subject' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'auto_renew' => ['nullable', 'boolean'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(config('glaust.statuses.contract')))],
            'project_id' => ['nullable', 'integer', TenantExists::in('projects')],
            'responsible_id' => ['nullable', 'integer', TenantExists::plain('users')],
            'parent_id' => ['nullable', 'integer', TenantExists::in('contracts'), Rule::notIn(array_filter([$contract?->id]))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'payments' => ['array', 'max:60'],
            'payments.*.due_date' => ['required', 'date'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.note' => ['nullable', 'string', 'max:190'],
            'payments.*.paid' => ['nullable', 'boolean'],
        ], [
            'counterparty_id.required' => 'Müqavilə yalnız CRM-də olan müştəri və ya təchizatçı ilə bağlana bilər. Kontragent seçin.',
            'counterparty_id.exists' => 'Seçilmiş kontragent sistemdə tapılmadı.',
            'number.unique' => 'Bu nömrə ilə müqavilə artıq var.',
        ], ['payments.*.due_date' => 'Ödəniş tarixi', 'payments.*.amount' => 'Ödəniş məbləği', 'parent_id' => 'Əsas müqavilə', 'responsible_id' => 'Məsul şəxs']);

        $counterparty = Counterparty::findOrFail($data['counterparty_id']);
        if ($data['kind'] === 'sale' && ! $counterparty->isCustomer()) {
            throw ValidationException::withMessages(['kind' => "«{$counterparty->name}» təchizatçıdır — onunla satış müqaviləsi bağlana bilməz. «Alış» seçin və ya kontragentin növünü dəyişin."]);
        }
        if ($data['kind'] === 'purchase' && ! $counterparty->isSupplier()) {
            throw ValidationException::withMessages(['kind' => "«{$counterparty->name}» müştəridir — onunla alış müqaviləsi bağlana bilməz. «Satış» seçin və ya kontragentin növünü dəyişin."]);
        }
        if (! empty($data['parent_id']) && Contract::find($data['parent_id'])?->counterparty_id !== $counterparty->id) {
            throw ValidationException::withMessages(['parent_id' => 'Əlavə razılaşma eyni kontragentlə olan müqaviləyə bağlanmalıdır.']);
        }

        $scheduled = round(array_sum(array_column($payments, 'amount')), 2);
        if ($scheduled > round((float) $data['amount'], 2) + 0.004) {
            throw ValidationException::withMessages(['payments' => 'Ödəniş qrafikinin cəmi ('.num($scheduled).') müqavilə məbləğindən ('.num($data['amount']).') çoxdur.']);
        }

        // Official CBAR rate on the contract date; no rate, no save.
        try {
            $rate = $this->rates->rate($data['currency'], $data['contract_date']);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['currency' => $e->getMessage().' Bir az sonra yenidən cəhd edin və ya tarixi yoxlayın.']);
        }

        $data['auto_renew'] = $request->boolean('auto_renew');
        $data['cbar_rate'] = $rate;
        $data['rate_date'] = $data['contract_date'];
        $data['amount_azn'] = round((float) $data['amount'] * $rate, 2);
        unset($data['payments']);

        return [$data, $payments];
    }

    private function syncPayments(Contract $contract, array $payments): void
    {
        $contract->payments()->delete();
        foreach ($payments as $p) {
            $contract->payments()->create([
                'due_date' => $p['due_date'],
                'amount' => $p['amount'],
                'note' => $p['note'] ?? null,
                'paid_at' => ! empty($p['paid']) ? now() : null,
            ]);
        }
    }
}
