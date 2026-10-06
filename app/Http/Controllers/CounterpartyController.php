<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Counterparty;
use App\Rules\Iban;
use App\Tables\CounterpartyTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CounterpartyController extends Controller
{
    public function index(Request $request): View
    {
        $table = new CounterpartyTable($request);
        $counts = Counterparty::selectRaw('type, COUNT(*) as c')->groupBy('type')->pluck('c', 'type');

        return view('counterparties.index', [
            'table' => $table,
            'items' => $table->paginate(),
            'counts' => [
                'all' => $counts->sum(),
                'customer' => ($counts['customer'] ?? 0) + ($counts['both'] ?? 0),
                'supplier' => ($counts['supplier'] ?? 0) + ($counts['both'] ?? 0),
                'logistics' => $counts['logistics'] ?? 0,
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        return (new CounterpartyTable($request))->export((string) $request->query('format', 'xlsx'));
    }

    public function create(Request $request): View
    {
        $this->authorize('crm.create');
        $type = in_array($request->query('type'), ['customer', 'supplier', 'both', 'logistics'], true) ? $request->query('type') : 'customer';

        return view('counterparties.form', ['item' => new Counterparty(['type' => $type, 'entity_type' => 'legal', 'country' => 'Azərbaycan'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('crm.create');
        $item = DB::transaction(function () use ($request) {
            [$data, $contacts] = $this->validated($request);
            $item = Counterparty::create($data);
            $this->syncContacts($item, $contacts);

            return $item;
        });

        // Opened from another form's "quick create" modal: return to it with the new id.
        if ($request->filled('return_to') && str_starts_with((string) $request->input('return_to'), url('/'))) {
            return redirect()->to($request->input('return_to'))->with('success', $item->name.__(' əlavə edildi.'))->with('created_counterparty', ['id' => $item->id, 'label' => $item->name]);
        }

        return redirect()->route('counterparties.show', $item)->with('success', $item->typeLabel().__(' əlavə edildi.'));
    }

    public function show(Counterparty $counterparty): View
    {
        $counterparty->load(['contacts', 'attachments.uploader']);
        $contracts = $counterparty->contracts()->latest('contract_date')->get();
        $projects = \App\Models\Project::where(fn ($w) => $w->where('counterparty_id', $counterparty->id)->orWhere('supplier_id', $counterparty->id))->withCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->where('status', 'done')])->latest()->get();
        $transactions = auth()->user()->can('bank.view')
            ? $counterparty->transactions()->with('account')->latest('transaction_date')->limit(15)->get()
            : collect();
        $shipments = auth()->user()->can('logistics.view') ? $counterparty->shipments()->latest()->limit(10)->get() : collect();

        $turnover = BankTransaction::where('counterparty_id', $counterparty->id)->where('kind', 'regular')
            ->selectRaw('direction, SUM(amount_azn) as s, COUNT(*) as n')->groupBy('direction')->get()->keyBy('direction');

        $history = AuditLog::with('user')->where('auditable_type', 'counterparty')->where('auditable_id', $counterparty->id)
            ->latest('created_at')->limit(15)->get();

        return view('counterparties.show', compact('counterparty', 'contracts', 'projects', 'transactions', 'shipments', 'turnover', 'history'));
    }

    public function edit(Counterparty $counterparty): View
    {
        $this->authorize('crm.update');

        return view('counterparties.form', ['item' => $counterparty->load('contacts')]);
    }

    public function update(Request $request, Counterparty $counterparty): RedirectResponse
    {
        $this->authorize('crm.update');
        DB::transaction(function () use ($request, $counterparty) {
            [$data, $contacts] = $this->validated($request, $counterparty);

            // A party with sales contracts must stay a customer, with purchase contracts a supplier.
            $kinds = $counterparty->contracts()->distinct()->pluck('kind');
            if ($kinds->contains('sale') && $data['type'] === 'supplier') {
                abort(back()->withInput()->withErrors(['type' => __('Bu kontragentlə satış müqavilələri var — növ «Təchizatçı» ola bilməz. «Müştəri və təchizatçı» seçin.')]));
            }
            if ($kinds->contains('purchase') && $data['type'] === 'customer') {
                abort(back()->withInput()->withErrors(['type' => __('Bu kontragentlə alış müqavilələri var — növ «Müştəri» ola bilməz. «Müştəri və təchizatçı» seçin.')]));
            }

            $counterparty->update($data);
            $this->syncContacts($counterparty, $contacts);
            if ($counterparty->wasChanged('director_name')) {
                \App\Support\Invoices\Signatories::syncCounterparty($counterparty);
            }
        });

        return redirect()->route('counterparties.show', $counterparty)->with('success', __('Məlumatlar yeniləndi.'));
    }

    public function destroy(Counterparty $counterparty): RedirectResponse
    {
        $this->authorize('crm.delete');
        $open = $counterparty->contracts()->whereNotIn('status', ['completed', 'cancelled'])->count();
        if ($open) {
            return back()->with('error', __('Bu kontragentin :v1 açıq müqaviləsi var. Əvvəlcə müqavilələri bağlayın və ya ləğv edin.', ['v1' => $open]));
        }
        $counterparty->delete();

        return redirect()->route('counterparties.index')->with('success', $counterparty->name.' silindi.');
    }

    /** @return array{0: array, 1: array} */
    private function validated(Request $request, ?Counterparty $item = null): array
    {
        $request->merge([
            'iban' => Iban::normalize($request->input('iban')),
            'voen' => preg_replace('/\D/', '', (string) $request->input('voen')) ?: null,
            'swift' => strtoupper(trim((string) $request->input('swift'))) ?: null,
        ]);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('glaust.counterparty_types')))],
            'entity_type' => ['required', Rule::in(array_keys(config('glaust.entity_types')))],
            'name' => ['required', 'string', 'max:190'],
            'director_name' => ['nullable', 'string', 'max:120'],
            'voen' => ['nullable', 'digits:10', Rule::unique('counterparties', 'voen')->where('company_id', tenant()->id)->ignore($item?->id)],
            'country' => ['required', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'],
            'iban' => ['nullable', new Iban],
            'bank_name' => ['nullable', 'string', 'max:190'],
            'swift' => ['nullable', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'tags' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'contacts' => ['nullable', 'array', 'max:20'],
            'contacts.*.name' => ['required_with:contacts.*.phone,contacts.*.email', 'nullable', 'string', 'max:120'],
            'contacts.*.position' => ['nullable', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.email' => ['nullable', 'email', 'max:190'],
        ], [
            'voen.unique' => __('Bu VÖEN ilə kontragent artıq mövcuddur (silinmişlər daxil).'),
            'swift.regex' => __('SWIFT/BIC kodu 8 və ya 11 simvol olmalıdır.'),
        ], ['swift' => 'SWIFT', 'contacts.*.name' => __('Əlaqə şəxsinin adı'), 'contacts.*.email' => __('Əlaqə şəxsinin emaili')]);

        $contacts = array_values(array_filter($data['contacts'] ?? [], fn ($c) => filled($c['name'] ?? null)));
        unset($data['contacts']);
        $data['tags'] = collect(explode(',', (string) ($data['tags'] ?? '')))->map(fn ($t) => trim($t))->filter()->unique()->implode(', ') ?: null;

        return [$data, $contacts];
    }

    private function syncContacts(Counterparty $item, array $contacts): void
    {
        $item->contacts()->delete();
        foreach ($contacts as $c) {
            $item->contacts()->create(array_intersect_key($c, array_flip(['name', 'position', 'phone', 'email'])));
        }
    }
}
