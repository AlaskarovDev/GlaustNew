<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Deal;
use App\Models\Expense;
use App\Rules\TenantExists;
use App\Services\ExpenseService;
use App\Support\Export\PdfExporter;
use App\Support\Export\SpreadsheetExporter;
use App\Tables\Column;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Xərclərin idarəetmə mərkəzi: expenses, their categories, paid / unpaid, cash / bank transfer. */
class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    public function index(Request $request): View|Response
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(Expense::STATUSES))], 'method' => ['nullable', Rule::in(array_keys(Expense::METHODS))],
            'q' => ['nullable', 'string', 'max:100'], 'format' => ['nullable', 'in:xlsx,pdf'],
        ]);
        $from = $f['from'] ?? today()->startOfMonth()->toDateString();
        $to = $f['to'] ?? today()->endOfMonth()->toDateString();

        $query = fn () => Expense::query()
            ->whereBetween('expense_date', [$from, $to.' 23:59:59'])
            ->when($f['category_id'] ?? null, fn (Builder $q, $c) => $q->where('category_id', $c))
            ->when($f['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($f['method'] ?? null, fn (Builder $q, $m) => $q->where('payment_method', $m))
            ->when($f['q'] ?? null, fn (Builder $q, $t) => $q->where(fn ($w) => $w->where('description', 'like', "%{$t}%")->orWhere('reference', 'like', "%{$t}%")));

        if ($format = $f['format'] ?? null) {
            $this->authorize('expenses.export');
            $rows = $query()->with(['category', 'counterparty', 'account'])->orderBy('expense_date')->get();
            $cols = [
                Column::make(__('Tarix'), 'expense_date', 'date'),
                Column::make(__('Kateqoriya'), fn ($e) => $e->category?->name ?? '—'),
                Column::make(__('Təsvir'), 'description'),
                Column::make(__('Kimə'), fn ($e) => $e->counterparty?->name),
                Column::make(__('Məbləğ'), 'amount', 'money'),
                Column::make(__('Valyuta'), 'currency'),
                Column::make('AZN', 'amount_azn', 'money', total: true),
                Column::make('Status', fn ($e) => __(Expense::STATUSES[$e->status][0])),
                Column::make(__('Ödəniş'), fn ($e) => $e->payment_method ? __(Expense::METHODS[$e->payment_method]).($e->account ? ' · '.$e->account->name : '') : '—'),
                Column::make(__('Ödəniş tarixi'), 'paid_at', 'date'),
            ];
            $name = 'xercler-'.$from.'-'.$to;
            $filters = [__('Dövr: ').azdate($from).' — '.azdate($to)];

            return $format === 'pdf'
                ? app(PdfExporter::class)->download(__('Xərclər'), $cols, $rows, $name.'.pdf', $filters)
                : app(SpreadsheetExporter::class)->download(__('Xərclər'), $cols, $rows, $name.'.xlsx', $filters);
        }

        $all = $query()->get(['id', 'status', 'amount_azn', 'category_id', 'due_date', 'payment_method']);
        $stats = [
            'total' => $all->sum('amount_azn'),
            'paid' => $all->where('status', 'paid')->sum('amount_azn'),
            'unpaid' => $all->where('status', 'unpaid')->sum('amount_azn'),
            'overdue' => $all->where('status', 'unpaid')->filter(fn ($e) => $e->due_date && $e->due_date->lt(today()))->count(),
            'bank' => $all->where('payment_method', 'bank')->sum('amount_azn'),
            'cash' => $all->where('payment_method', 'cash')->sum('amount_azn'),
        ];
        $categories = Category::where('scope', 'expense')->orderBy('name')->get();
        $byCategory = $all->groupBy(fn ($e) => $e->category_id ?? 0)->map(fn ($g) => $g->sum('amount_azn'))->sortDesc();

        return view('expenses.index', [
            'expenses' => $query()->with(['category', 'counterparty', 'account', 'deal'])->orderByDesc('expense_date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'stats' => $stats, 'categories' => $categories, 'byCategory' => $byCategory, 'from' => $from, 'to' => $to, 'filters' => $f,
            'unpaidAll' => Expense::where('status', 'unpaid')->sum('amount_azn'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('expenses.create');
        $deal = $request->integer('deal_id') ? Deal::find($request->integer('deal_id')) : null;

        return $this->form(new Expense([
            'expense_date' => today(), 'currency' => 'AZN', 'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => today(),
            'deal_id' => $deal?->id, 'project_id' => $deal?->project_id,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('expenses.create');
        $expense = $this->expenses->save(new Expense, $this->validated($request));

        return redirect()->to($request->boolean('another') ? route('expenses.create') : route('expenses.index'))
            ->with('success', __('Xərc əlavə edildi').($expense->bank_transaction_id ? __(' və ').$expense->account?->name.__(' hesabından silindi') : '').'.');
    }

    public function edit(Request $request, Expense $expense): View
    {
        $this->authorize('expenses.update');
        if ($request->boolean('pay') && ! $expense->isPaid()) {
            $expense->status = 'paid';
            $expense->payment_method = 'bank';
            $expense->paid_at = today();
        }

        return $this->form($expense);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.update');
        $this->expenses->save($expense, $this->validated($request));

        return redirect()->route('expenses.index')->with('success', __('Xərc yeniləndi.'));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.delete');
        $this->expenses->delete($expense);

        return back()->with('success', __('Xərc silindi').($expense->payment_method === 'bank' ? __('; bank hesabından silinmə də ləğv edildi') : '').'.');
    }

    /* ----- categories (Xərc kateqoriyaları) ----- */

    public function categories(): View
    {
        $categories = Category::where('scope', 'expense')->withCount('expenses')->withSum('expenses', 'amount_azn')->orderBy('name')->get();

        return view('expenses.categories', compact('categories'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('expenses.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories')->where('company_id', tenant()->id)->where('scope', 'expense')],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => __('Bu adda kateqoriya var.')], ['name' => 'Ad']);
        Category::create($data + ['scope' => 'expense']);

        return back()->with('success', __('Kateqoriya əlavə edildi: ').$data['name'].'.');
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('expenses.update');
        abort_unless($category->scope === 'expense', 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories')->where('company_id', tenant()->id)->where('scope', 'expense')->ignore($category->id)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => __('Bu adda kateqoriya var.')], ['name' => 'Ad']);
        $category->update($data);

        return back()->with('success', __('Kateqoriya yeniləndi.'));
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        $this->authorize('expenses.delete');
        abort_unless($category->scope === 'expense', 404);
        $used = $category->expenses()->count();
        $category->delete();

        return back()->with('success', __('Kateqoriya silindi').($used ? __(' (:v1 xərc kateqoriyasız qaldı)', ['v1' => $used]) : '').'.');
    }

    private function form(Expense $expense): View
    {
        return view('expenses.form', [
            'expense' => $expense,
            'categories' => Category::where('scope', 'expense')->orderBy('name')->get(),
            'accounts' => BankAccount::where('is_active', true)->withBalance()->orderBy('bank_name')->orderBy('currency')->get(),
        ]);
    }

    private function validated(Request $request): array
    {
        $request->merge(['amount' => parse_number($request->input('amount'))]);

        return $request->validate([
            'expense_date' => ['required', 'date'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('company_id', tenant()->id)->where('scope', 'expense')],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'counterparty_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'project_id' => ['nullable', 'integer', TenantExists::in('projects')],
            'deal_id' => ['nullable', 'integer', TenantExists::in('deals')],
            'status' => ['required', Rule::in(array_keys(Expense::STATUSES))],
            'due_date' => ['nullable', 'date'],
            'payment_method' => ['required_if:status,paid', 'nullable', Rule::in(array_keys(Expense::METHODS))],
            'paid_at' => ['required_if:status,paid', 'nullable', 'date', 'before_or_equal:today'],
            'bank_account_id' => ['required_if:payment_method,bank', 'nullable', 'integer', TenantExists::in('bank_accounts')],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['bank_account_id.required_if' => __('Hesabdan köçürmə üçün bank hesabını seçin.'), 'paid_at.required_if' => __('Ödəniş tarixini seçin.'), 'payment_method.required_if' => __('Ödəniş üsulunu seçin.')],
            ['expense_date' => __('Tarix'), 'category_id' => __('Kateqoriya'), 'description' => __('Təsvir'), 'amount' => __('Məbləğ'), 'paid_at' => __('Ödəniş tarixi'), 'bank_account_id' => __('Bank hesabı')]);
    }
}
