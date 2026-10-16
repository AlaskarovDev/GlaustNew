<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankAudit;
use App\Models\BankAuditLine;
use App\Models\BankTransaction;
use App\Rules\SpreadsheetFile;
use App\Rules\TenantExists;
use App\Services\BankAuditFixer;
use App\Services\Cbar\RateUnavailable;
use App\Support\BankAudit\Reconciler;
use App\Support\BankAudit\StatementReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Bank hesabları auditi: the bank's statement uploaded, compared with the system's movements, fixed line by line. */
class BankAuditController extends Controller
{
    public function index(): View
    {
        $audits = BankAudit::with('account', 'creator')->withCount('lines')->latest()->get();

        return view('bank.audits.index', [
            'audits' => $audits,
            'accounts' => BankAccount::orderBy('bank_name')->orderBy('currency')->get(),
            'issues' => $audits->mapWithKeys(fn ($a) => [$a->id => self::issueCount((new Reconciler($a))->run()['counts'])]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('bank.import');
        $request->validate([
            'file' => ['required', 'file', 'max:10240', new SpreadsheetFile(['xlsx', 'xls'])],
            'bank_account_id' => ['nullable', 'integer', TenantExists::in('bank_accounts')],
        ]);
        try {
            $s = StatementReader::read($request->file('file')->getRealPath());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        // the account: by the statement's IBAN, or the one chosen (it then keeps the IBAN)
        $account = $s['iban'] ? BankAccount::where('iban', $s['iban'])->first() : null;
        if (! $account && $request->filled('bank_account_id')) {
            $account = BankAccount::findOrFail($request->integer('bank_account_id'));
        }
        if (! $account) {
            return back()->withInput()->withErrors(['bank_account_id' => __('IBAN :v1 heç bir hesaba yazılmayıb — çıxarışın aid olduğu hesabı seçin.', ['v1' => $s['iban'] ?? '—'])]);
        }
        if ($account->currency !== $s['currency']) {
            return back()->withInput()->withErrors(['bank_account_id' => __('Çıxarış :v1 hesabınındır, seçilən hesab isə :v2. Eyni valyutalı hesab seçin.', ['v1' => $s['currency'], 'v2' => $account->currency])]);
        }

        $audit = DB::transaction(function () use ($s, $account, $request) {
            if ($s['iban'] && ! $account->iban) {
                $account->update(['iban' => $s['iban']]);
            }
            $audit = BankAudit::create(['bank_account_id' => $account->id, 'file_name' => $request->file('file')->getClientOriginalName(),
                'iban' => $s['iban'], 'currency' => $s['currency'], 'period_from' => $s['from'], 'period_to' => $s['to'],
                'opening' => $s['opening'], 'closing' => $s['closing'], 'created_by' => auth()->id()]);
            foreach ($s['lines'] as $l) {
                $audit->lines()->create(['line_no' => $l['no'], 'line_date' => $l['date'], 'amount' => $l['amount'], 'amount_azn' => $l['azn'],
                    'balance' => $l['balance'], 'description' => $l['description']]);
            }

            return $audit;
        });

        return redirect()->route('bank.audits.show', $audit)->with('success', __('Çıxarış yükləndi: :v1 sətir, :v2 — :v3.', ['v1' => count($s['lines']), 'v2' => azdate($s['from']), 'v3' => azdate($s['to'])]));
    }

    public function show(Request $request, BankAudit $audit): View
    {
        $result = (new Reconciler($audit))->run();
        $filter = in_array($request->query('show'), ['all', 'ok'], true) ? $request->query('show') : 'issues';

        return view('bank.audits.show', ['audit' => $audit, 'r' => $result, 'filter' => $filter, 'issues' => self::issueCount($result['counts'])]);
    }

    /** One «Düzəlt»: action create | date | amount | delete. */
    public function fix(Request $request, BankAudit $audit, BankAuditFixer $fixer): RedirectResponse
    {
        $this->authorize('bank.update');
        $d = $request->validate(['action' => ['required', 'in:create,date,amount,delete'], 'line' => ['nullable', 'integer'], 'tx' => ['nullable', 'integer'],
            'txs' => ['nullable', 'array', 'max:4'], 'txs.*' => ['integer']]);
        $line = isset($d['line']) ? BankAuditLine::where('bank_audit_id', $audit->id)->findOrFail($d['line']) : null;
        // one movement, or — a bank line booked in parts — the parts that sit on another day
        $ids = isset($d['txs']) ? $d['txs'] : (isset($d['tx']) ? [$d['tx']] : []);
        $txs = BankTransaction::where('bank_account_id', $audit->bank_account_id)->whereKey($ids)->get();
        $tx = $txs->first();
        if (($d['action'] !== 'delete' && ! $line) || ($d['action'] !== 'create' && (! $tx || $txs->count() !== count(array_unique($ids))))
            || (count($ids) > 1 && $d['action'] !== 'date')) {
            abort(422);
        }

        try {
            match ($d['action']) {
                'create' => $fixer->create($audit->account, $line),
                'date' => $txs->each(fn ($t) => $fixer->date($t, $line->line_date->toDateString())),
                'amount' => $fixer->amount($tx, $line),
                'delete' => $fixer->delete($tx),
            };
            $message = match ($d['action']) {
                'create' => __('Əməliyyat sistemə əlavə olundu — bank çıxarışındakı kimi.'),
                'date' => __('Tarix :v1 edildi; CBAR kursları həmin günə yenidən hesablandı.', ['v1' => azdate($line->line_date)]),
                'amount' => __('Məbləğ :v1 edildi.', ['v1' => money(abs((float) $line->amount), $audit->currency)]),
                'delete' => __('Bankda olmayan əməliyyat sistemdən silindi.'),
            };
        } catch (RateUnavailable $e) {
            return back()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', $message);
    }

    public function destroy(BankAudit $audit): RedirectResponse
    {
        $this->authorize('bank.import');
        $audit->delete();

        return redirect()->route('bank.audits.index')->with('success', __('Çıxarış silindi. Sistemdəki əməliyyatlara toxunulmadı.'));
    }

    private static function issueCount(array $counts): int
    {
        return array_sum(array_intersect_key($counts, array_flip(['date', 'amount', 'missing_sys', 'missing_bank'])));
    }
}
