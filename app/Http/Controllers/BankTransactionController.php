<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Rules\TenantExists;
use App\Services\BankLedger;
use App\Services\Cbar\RateUnavailable;
use App\Tables\BankTransactionTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BankTransactionController extends Controller
{
    public function __construct(private BankLedger $ledger) {}

    public function index(Request $request): View
    {
        $table = new BankTransactionTable($request);
        $accounts = BankAccount::withBalance()->where('is_active', true)->orderBy('currency')->orderBy('name')->get();

        return view('bank.transactions.index', [
            'table' => $table,
            'items' => $table->paginate(),
            'totals' => $table->totals(),
            'accounts' => $accounts,
        ]);
    }

    public function export(Request $request): Response
    {
        return (new BankTransactionTable($request))->export((string) $request->query('format', 'xlsx'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('bank.create');
        if (! BankAccount::where('is_active', true)->exists()) {
            return redirect()->route('bank.accounts.create')->with('info', 'Əvvəlcə bank hesabı əlavə edin.');
        }
        $tx = new BankTransaction([
            'direction' => $request->query('direction') === 'out' ? 'out' : 'in',
            'transaction_date' => today(),
            'bank_account_id' => $request->integer('account_id') ?: null,
            'counterparty_id' => $request->integer('counterparty_id') ?: null,
            'contract_id' => $request->integer('contract_id') ?: null,
            'project_id' => $request->integer('project_id') ?: null,
        ]);
        $tx->load(['counterparty', 'contract', 'project']);

        return view('bank.transactions.form', $this->formData($tx, $request->query('mode', 'regular')));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('bank.create');
        $mode = $request->input('mode') === 'transfer' ? 'transfer' : 'regular';

        try {
            if ($mode === 'transfer') {
                $data = $this->validatedTransfer($request);
                [$out] = $this->ledger->transfer(
                    BankAccount::findOrFail($data['from_account_id']), BankAccount::findOrFail($data['to_account_id']),
                    $data['transaction_date'], (float) $data['amount_out'], (float) ($data['amount_in'] ?? $data['amount_out']),
                    ['purpose' => $data['purpose'] ?? null, 'reference' => $data['reference'] ?? null],
                );

                return redirect()->route('bank.transactions.show', $out)->with('success', $out->kind === 'conversion' ? 'Konvertasiya qeydə alındı.' : 'Köçürmə qeydə alındı.');
            }

            [$account, $data, $applied] = $this->validatedRegular($request);
            $tx = $this->ledger->record($account, $data, $applied);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['transaction_date' => $e->getMessage().' Əməliyyat yadda saxlanmadı.']);
        }

        return redirect()->to($request->boolean('another')
            ? route('bank.transactions.create', ['direction' => $tx->direction, 'account_id' => $tx->bank_account_id])
            : route('bank.transactions.show', $tx))->with('success', 'Əməliyyat qeydə alındı.');
    }

    public function show(BankTransaction $transaction): View
    {
        $transaction->load(['account', 'counterparty', 'contract', 'project', 'category', 'creator', 'attachments.uploader']);
        $counterpart = $transaction->counterpart()?->load('account');
        $history = AuditLog::with('user')->where('auditable_type', 'bank_transaction')->where('auditable_id', $transaction->id)->latest('created_at')->get();

        return view('bank.transactions.show', ['tx' => $transaction, 'counterpart' => $counterpart, 'history' => $history]);
    }

    public function edit(BankTransaction $transaction): View|RedirectResponse
    {
        $this->authorize('bank.update');
        if ($transaction->transfer_group) {
            return redirect()->route('bank.transactions.show', $transaction)->with('info', 'Köçürmə və konvertasiyanı redaktə etmək olmur: silib yenidən daxil edin.');
        }
        $transaction->load(['counterparty', 'contract', 'project']);

        return view('bank.transactions.form', $this->formData($transaction, 'regular'));
    }

    public function update(Request $request, BankTransaction $transaction): RedirectResponse
    {
        $this->authorize('bank.update');
        abort_if((bool) $transaction->transfer_group, 403);
        try {
            [$account, $data, $applied] = $this->validatedRegular($request, $transaction);
            unset($data['bank_account_id']);
            $this->ledger->update($transaction, $data, $applied);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['transaction_date' => $e->getMessage()]);
        }

        return redirect()->route('bank.transactions.show', $transaction)->with('success', 'Əməliyyat yeniləndi.');
    }

    public function destroy(BankTransaction $transaction): RedirectResponse
    {
        $this->authorize('bank.delete');
        $n = $this->ledger->delete($transaction);

        return redirect()->route('bank.transactions.index')->with('success', $n > 1 ? 'Köçürmənin hər iki tərəfi silindi.' : 'Əməliyyat silindi.');
    }

    private function formData(BankTransaction $tx, string $mode): array
    {
        return [
            'tx' => $tx,
            'mode' => $mode === 'transfer' ? 'transfer' : 'regular',
            'accounts' => BankAccount::where('is_active', true)->orderBy('currency')->orderBy('name')->get(),
            'categories' => Category::where('scope', 'bank')->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** @return array{0: BankAccount, 1: array, 2: ?float} */
    private function validatedRegular(Request $request, ?BankTransaction $tx = null): array
    {
        $request->merge([
            'amount' => parse_number($request->input('amount')),
            'applied_rate' => $request->boolean('override_rate') ? parse_number($request->input('applied_rate')) : null,
        ]);
        $data = $request->validate([
            'bank_account_id' => [$tx ? 'nullable' : 'required', 'integer', TenantExists::in('bank_accounts')],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'applied_rate' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'counterparty_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'contract_id' => ['nullable', 'integer', TenantExists::in('contracts')],
            'project_id' => ['nullable', 'integer', TenantExists::in('projects')],
            'category_id' => ['nullable', 'integer', TenantExists::plain('categories')],
            'purpose' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:80'],
        ], [], ['transaction_date' => 'Tarix', 'applied_rate' => 'Bank məzənnəsi', 'category_id' => 'Kateqoriya']);

        $account = $tx ? $tx->account : BankAccount::findOrFail($data['bank_account_id']);

        // Contract implies its counterparty; a mismatch is an error, not a silent fix.
        if (! empty($data['contract_id'])) {
            $contract = Contract::findOrFail($data['contract_id']);
            if (! empty($data['counterparty_id']) && (int) $data['counterparty_id'] !== $contract->counterparty_id) {
                throw ValidationException::withMessages(['contract_id' => "Müqavilə {$contract->number} başqa kontragentə aiddir."]);
            }
            $data['counterparty_id'] = $contract->counterparty_id;
            $data['project_id'] ??= $contract->project_id;
        }

        $applied = $data['applied_rate'] ?? null;
        unset($data['applied_rate']);

        return [$account, $data, $applied];
    }

    private function validatedTransfer(Request $request): array
    {
        $request->merge(['amount_out' => parse_number($request->input('amount_out')), 'amount_in' => parse_number($request->input('amount_in'))]);
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', TenantExists::in('bank_accounts')],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'amount_out' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'amount_in' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:80'],
        ], ['to_account_id.different' => 'Göndərən və alan hesab eyni ola bilməz.'], [
            'from_account_id' => 'Göndərən hesab', 'to_account_id' => 'Alan hesab', 'amount_out' => 'Silinən məbləğ', 'amount_in' => 'Daxil olan məbləğ', 'transaction_date' => 'Tarix',
        ]);

        $from = BankAccount::findOrFail($data['from_account_id']);
        $to = BankAccount::findOrFail($data['to_account_id']);
        if ($from->currency !== $to->currency && empty($data['amount_in'])) {
            throw ValidationException::withMessages(['amount_in' => "Konvertasiyada daxil olan məbləği ({$to->currency}) yazın."]);
        }

        return $data;
    }

    public static function counterpartyName(?int $id): ?string
    {
        return $id ? Counterparty::find($id)?->name : null;
    }
}
