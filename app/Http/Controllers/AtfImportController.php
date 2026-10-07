<?php

namespace App\Http\Controllers;

use App\Imports\Atf\AtfImporter;
use App\Imports\Atf\AtfSheet;
use App\Models\BankAccount;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Project;
use App\Rules\SpreadsheetFile;
use App\Rules\TenantExists;
use App\Services\NumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Toplu Trade importu from the company's ATF workbook: upload + who is who (project, seller, buyer, carrier,
 * accounts) → preview of every row → each row worked through the system one by one (AJAX), as if entered by hand.
 */
class AtfImportController extends Controller
{
    private const TTL_HOURS = 6;

    public function create(): View
    {
        $this->authorize('projects.create');
        $this->authorize('bank.create');
        $parties = Counterparty::orderBy('name')->get(['id', 'name', 'type']);
        $guess = fn (string $needle) => $parties->first(fn ($c) => str_contains(mb_strtolower($c->name), $needle))?->id;

        return view('imports.atf', [
            'projects' => Project::orderByDesc('start_date')->orderByDesc('id')->get(['id', 'code', 'name']),
            'parties' => $parties,
            'contracts' => Contract::with('counterparty:id,name')->orderByDesc('contract_date')->get(['id', 'number', 'kind', 'counterparty_id']),
            'accounts' => BankAccount::where('is_active', true)->orderBy('currency')->orderBy('name')->get(['id', 'name', 'bank_name', 'currency']),
            'guess' => ['supplier' => $guess('ellis'), 'buyer' => $guess('axios'), 'logistics' => $parties->firstWhere('type', 'logistics')?->id],
        ]);
    }

    /** The ATF template: the company's sheet layout (A…BT) with its headers, ready for pasted rows. */
    public function template(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $book = AtfSheet::template();

        return response()->streamDownload(fn () => (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output'), 'tradeflow-atf-sablon.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request, NumberGenerator $numbers): RedirectResponse
    {
        $this->authorize('projects.create');
        $this->authorize('bank.create');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', new SpreadsheetFile],
            'project_id' => ['required_without:project_name', 'nullable', 'integer', TenantExists::in('projects')],
            'project_name' => ['nullable', 'string', 'max:160'],
            'supplier_id' => ['required', 'integer', TenantExists::in('counterparties')],
            'buyer_id' => ['required', 'integer', TenantExists::in('counterparties'), 'different:supplier_id'],
            'logistics_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'purchase_contract_id' => ['nullable', 'integer', TenantExists::in('contracts')],
            'sale_contract_id' => ['nullable', 'integer', TenantExists::in('contracts')],
            'eur_account' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'rub_account' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'azn_account' => ['required', 'integer', TenantExists::in('bank_accounts')],
        ], ['project_id.required_without' => __('Layihə seçin və ya yeni layihənin adını yazın.')], [
            'file' => __('Excel faylı'), 'supplier_id' => __('Satıcı'), 'buyer_id' => __('Alıcı'), 'eur_account' => __('EUR hesabı'), 'rub_account' => __('RUB hesabı'), 'azn_account' => __('AZN hesabı'),
        ]);
        foreach (['eur_account' => 'EUR', 'rub_account' => 'RUB', 'azn_account' => 'AZN'] as $field => $cur) {
            if (BankAccount::find($data[$field])?->currency !== $cur) {
                throw ValidationException::withMessages([$field => __(':v1 valyutasında hesab seçin.', ['v1' => $cur])]);
            }
        }

        try {
            $parsed = AtfSheet::parse($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('Fayl oxunmadı. ATF cədvəlinin .xlsx faylını seçin.'));
        }
        if ($parsed['errors']) {
            return back()->withInput()->with('error', implode(' ', $parsed['errors']));
        }

        if (filled($data['project_name'] ?? null)) {   // a new project's name wins over a chosen one
            $project = Project::create(['code' => $numbers->next('project'), 'name' => $data['project_name'], 'status' => 'active', 'priority' => 'medium',
                'currency' => 'EUR', 'counterparty_id' => $data['buyer_id'], 'manager_id' => $request->user()->id,
                'start_date' => collect($parsed['rows'])->min('seller_date')]);
            $data['project_id'] = $project->id;
        }
        unset($data['file'], $data['project_name']);

        $token = Str::random(24);
        Cache::put($this->key($token), ['company' => tenant()->id, 'user' => $request->user()->id, 'file' => $request->file('file')->getClientOriginalName(),
            'setup' => $data, 'rows' => $parsed['rows']], now()->addHours(self::TTL_HOURS));

        return redirect()->route('imports.atf.show', $token);
    }

    public function show(string $token): View|RedirectResponse
    {
        $job = $this->job($token);
        if (! $job) {
            return redirect()->route('imports.atf.create')->with('error', __('Import sessiyası bitib — faylı yenidən yükləyin.'));
        }
        $s = $job['setup'];
        $rows = array_map(fn ($r) => $r + ['exists' => (bool) AtfImporter::existing($s['project_id'], (string) $r['seller_no'])], $job['rows']);

        return view('imports.atf-preview', [
            'token' => $token, 'job' => $job, 'rows' => $rows,
            'project' => Project::find($s['project_id']),
            'parties' => Counterparty::whereIn('id', array_filter([$s['supplier_id'], $s['buyer_id'], $s['logistics_id'] ?? null]))->pluck('name', 'id'),
            'accounts' => BankAccount::whereIn('id', [$s['eur_account'], $s['rub_account'], $s['azn_account']])->get()->keyBy('id'),
        ]);
    }

    /** One row through the system; the page calls this row after row. */
    public function row(Request $request, string $token, int $n, AtfImporter $importer): JsonResponse
    {
        $job = $this->job($token);
        if (! $job) {
            return response()->json(['ok' => false, 'message' => __('Import sessiyası bitib — faylı yenidən yükləyin.')]);
        }
        $row = $job['rows'][$n] ?? null;
        abort_unless($row, 404);
        if ($row['errors']) {
            return response()->json(['ok' => false, 'message' => implode('; ', $row['errors'])]);
        }
        if ($inv = AtfImporter::existing($job['setup']['project_id'], (string) $row['seller_no'])) {
            return response()->json(['ok' => true, 'skipped' => true, 'message' => __('Artıq var — Trade :v1', ['v1' => $inv->deal?->code]), 'url' => $inv->deal ? route('deals.show', $inv->deal) : null]);
        }
        @set_time_limit(120);
        try {
            $res = $importer->import($row, $job['setup'], $request->user()->id);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => __('Gözlənilməz xəta: ').Str::limit($e->getMessage(), 160)]);
        }

        return response()->json(['ok' => true, 'code' => $res['deal']->code, 'url' => route('deals.show', $res['deal']), 'steps' => $res['steps']]);
    }

    private function key(string $token): string
    {
        return 'atf-import:'.$token;
    }

    /** The upload of this company and user, while it is fresh. */
    private function job(string $token): ?array
    {
        $job = Cache::get($this->key($token));

        return $job && $job['company'] === tenant()->id && $job['user'] === auth()->id() ? $job : null;
    }
}
