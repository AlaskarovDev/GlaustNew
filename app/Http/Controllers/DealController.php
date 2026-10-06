<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Project;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\NumberGenerator;
use App\Support\ContractSides;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Trade: a buy-and-resell lot of a project, with both contracts and its invoices. */
class DealController extends Controller
{
    public function create(Request $request, Project $project, NumberGenerator $numbers): View
    {
        $this->authorize('projects.create');
        $project->load('counterparty', 'supplier', 'saleContract', 'purchaseContract');

        // The project's sides are the natural default for its first lot.
        $deal = new Deal([
            'code' => $numbers->next('deal'), 'title' => 'Trade — '.$project->name, 'deal_date' => today(), 'currency' => 'EUR',
            'status' => 'draft', 'responsible_id' => $request->user()->id,
            'counterparty_id' => $project->counterparty_id, 'sale_contract_id' => $project->sale_contract_id,
            'supplier_id' => $project->supplier_id, 'purchase_contract_id' => $project->purchase_contract_id,
        ]);
        $deal->setRelation('project', $project);
        foreach (['counterparty', 'supplier', 'saleContract', 'purchaseContract'] as $rel) {
            $deal->setRelation($rel, $project->{$rel});
        }

        return view('deals.form', ['deal' => $deal, 'project' => $project]);
    }

    public function store(Request $request, Project $project, NumberGenerator $numbers): RedirectResponse
    {
        $this->authorize('projects.create');
        if (blank($request->input('code'))) {
            $request->merge(['code' => $numbers->next('deal')]);
        }
        $deal = DB::transaction(function () use ($request, $project) {
            $deal = Deal::create($this->validated($request) + ['project_id' => $project->id]);
            $this->storeContractFiles($request, $deal);

            return $deal;
        });

        return redirect()->route('deals.show', $deal)->with('success', __('Trade :v1 yaradıldı. İndi təchizatçı fakturasını Excel-dən import edin.', ['v1' => $deal->code]));
    }

    /** Tabs follow the order of the work: invoices (buy, calculate, documents) -> income (buyer pays) -> logistics. */
    public const TABS = ['invoices', 'income', 'logistics', 'contracts'];

    public function show(Request $request, Deal $deal): View
    {
        $deal->load([
            'project', 'counterparty', 'supplier', 'responsible',
            'saleContract.attachments.uploader', 'purchaseContract.attachments.uploader',
            'invoices' => fn ($q) => $q->withCount('items'), 'attachments.uploader',
            'salesDocuments', 'payments.account', 'supplierPayments.account', 'supplierPayments.feeAccount', 'supplierPayments.feeExpense',
            'logisticsActs.payments.account', 'logisticsActs.attachments', 'logisticsActs.counterparty', 'logisticsActs.invoice', 'logisticsActs.reminder',
        ]);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'invoices';
        $history = AuditLog::with('user')->where('auditable_type', 'deal')->where('auditable_id', $deal->id)->latest('created_at')->limit(15)->get();
        $accounts = $request->user()->can('bank.create')
            ? \App\Models\BankAccount::where('is_active', true)->orderBy('currency')->orderBy('name')->get(['id', 'name', 'bank_name', 'currency'])
            : collect();

        return view('deals.show', compact('deal', 'history', 'tab', 'accounts'));
    }

    public function edit(Deal $deal): View
    {
        $this->authorize('projects.update');
        $deal->load('project', 'counterparty', 'supplier', 'saleContract', 'purchaseContract');

        return view('deals.form', ['deal' => $deal, 'project' => $deal->project]);
    }

    public function update(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('projects.update');
        DB::transaction(function () use ($request, $deal) {
            $data = $this->validated($request, $deal);
            // Invoices are bound to the side's contract: it cannot be swapped under them.
            foreach (['purchase_contract_id' => 'supplier', 'sale_contract_id' => 'customer'] as $field => $type) {
                if ((int) ($data[$field] ?? 0) !== (int) $deal->{$field} && $deal->invoices()->where('type', $type)->exists()) {
                    throw ValidationException::withMessages([$field => __('Bu müqaviləyə bağlı fakturalar var — əvvəlcə fakturaları silin.')]);
                }
            }
            $deal->update($data);
            $this->storeContractFiles($request, $deal);
        });

        return redirect()->route('deals.show', $deal)->with('success', __('Trade yeniləndi.'));
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        $this->authorize('projects.delete');
        if ($deal->invoices()->exists()) {
            return back()->with('error', __('Trade-də fakturalar var. Əvvəlcə fakturaları silin.'));
        }
        $project = $deal->project_id;
        $deal->delete();

        return redirect()->route('projects.show', [$project, 'tab' => 'deals'])->with('success', __('Trade :v1 silindi.', ['v1' => $deal->code]));
    }

    private function validated(Request $request, ?Deal $deal = null): array
    {
        $data = $request->validate(array_merge([
            'code' => ['required', 'string', 'max:32', Rule::unique('deals', 'code')->where('company_id', tenant()->id)->ignore($deal?->id)],
            'title' => ['required', 'string', 'max:190'],
            'deal_date' => ['required', 'date'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'status' => ['required', Rule::in(Deal::STATUSES)],
            'responsible_id' => ['nullable', 'integer', TenantExists::plain('users')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'sale_contract_file' => ['nullable', 'file', 'mimes:pdf', 'max:'.config('glaust.upload.max_kb')],
            'purchase_contract_file' => ['nullable', 'file', 'mimes:pdf', 'max:'.config('glaust.upload.max_kb')],
        ], ContractSides::rules()), ['code.unique' => __('Bu kodla Trade artıq var.')], ContractSides::attributes() + [
            'code' => __('Kod'), 'title' => 'Ad', 'deal_date' => __('Tarix'), 'currency' => __('Alış valyutası'), 'sale_contract_file' => __('Satış müqaviləsinin PDF-i'), 'purchase_contract_file' => __('Alış müqaviləsinin PDF-i'),
        ]);

        foreach (['sale' => 'sale_contract', 'purchase' => 'purchase_contract'] as $side => $prefix) {
            if ($request->hasFile($prefix.'_file') && empty($data[$prefix.'_id'])) {
                throw ValidationException::withMessages([$prefix.'_file' => __('PDF yükləmək üçün əvvəlcə müqaviləni seçin.')]);
            }
        }
        unset($data['sale_contract_file'], $data['purchase_contract_file']);

        return ContractSides::check($data);
    }

    /** Signed contract scans (PDF) are kept on the contract itself, where everyone finds them. */
    private function storeContractFiles(Request $request, Deal $deal): void
    {
        foreach (['sale_contract' => $deal->sale_contract_id, 'purchase_contract' => $deal->purchase_contract_id] as $field => $contractId) {
            $file = $request->file($field.'_file');
            if (! $file || ! $contractId) {
                continue;
            }
            $contract = Contract::findOrFail($contractId);
            $path = $file->storeAs('attachments/'.tenant()->id.'/'.now()->format('Y/m'), Str::uuid().'.pdf', 'local');
            $contract->attachments()->create([
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'path' => $path,
                'mime' => 'application/pdf', 'size' => $file->getSize(), 'uploaded_by' => auth()->id(),
            ]);
        }
    }

    public static function users(): array
    {
        return User::forTenant()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
