<?php

namespace App\Http\Controllers;

use App\Imports\BankTransactionImporter;
use App\Imports\ImportRunner;
use App\Jobs\ProcessImport;
use App\Models\BankAccount;
use App\Models\Import;
use App\Rules\SpreadsheetFile;
use App\Rules\TenantExists;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(Request $request): View
    {
        $types = collect(ImportRunner::TYPES)->filter(fn ($c) => $request->user()->can($c::ability()));
        abort_if($types->isEmpty(), 403);
        $history = Import::with('user')->whereIn('type', $types->map(fn ($c) => $c::type()))->latest()->limit(30)->get();

        return view('imports.index', [
            'types' => $types,
            'selected' => $request->query('type', $types->first()::type()),
            'history' => $history,
            'accounts' => $request->user()->can('bank.import') ? BankAccount::where('is_active', true)->orderBy('name')->get() : collect(),
        ]);
    }

    public function template(Request $request, string $type): StreamedResponse
    {
        $class = $this->typeClass($type);
        $book = ImportRunner::template($class === BankTransactionImporter::class ? new BankTransactionImporter : new $class);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'glaust-import-'.$type.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string'],
            'file' => ['required', 'file', 'max:10240', new SpreadsheetFile(['xlsx', 'xls', 'csv', 'txt'])],
            'account_id' => ['nullable', 'integer', TenantExists::in('bank_accounts')],
        ], [], ['file' => __('Fayl'), 'account_id' => __('Bank hesabı')]);
        $class = $this->typeClass($data['type']);
        if ($class === BankTransactionImporter::class && empty($data['account_id'])) {
            return back()->withInput()->withErrors(['account_id' => __('Çıxarışın aid olduğu bank hesabını seçin.')]);
        }

        $file = $request->file('file');
        $path = $file->storeAs('imports/'.tenant()->id, Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        try {
            $sheet = ImportRunner::read(Storage::disk('local')->path($path));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => __('Fayl oxunmadı. Excel (.xlsx) və ya CSV faylı yükləyin.')]);
        }
        if (! $sheet['headers'] || ! $sheet['rows']) {
            Storage::disk('local')->delete($path);

            return back()->withErrors(['file' => __('Faylda başlıq sətri və ya məlumat yoxdur.')]);
        }

        $importer = $class === BankTransactionImporter::class ? new BankTransactionImporter : new $class;
        $mapping = $importer->guessMapping($sheet['headers']);
        if ($class === BankTransactionImporter::class) {
            $mapping['__account'] = (int) $data['account_id'];
        }

        $import = Import::create([
            'user_id' => $request->user()->id, 'type' => $class::type(), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'path' => $path, 'mapping' => $mapping, 'total_rows' => count($sheet['rows']),
        ]);

        return redirect()->route('imports.show', $import);
    }

    public function show(Request $request, Import $import): View
    {
        $class = $this->typeClass($import->type);
        if ($request->has('map')) {
            $import->update(['mapping' => $this->mappingFromRequest($request, $import)]);
        }
        $importer = ImportRunner::importerFor($import);
        $sheet = ImportRunner::read(Storage::disk('local')->path($import->path), 25);

        $preview = [];
        if ($import->status === 'uploaded') {
            foreach ($sheet['rows'] as $line => $cells) {
                $mapped = ImportRunner::mapRow($cells, $import->mapping ?? []);
                $preview[] = ['line' => $line, 'values' => $mapped, 'error' => $importer->check($mapped)];
            }
        }
        $missing = collect($importer->fields())->filter(fn ($f, $k) => ! empty($f['required']) && ! isset($import->mapping[$k]))->keys();

        return view('imports.show', [
            'import' => $import, 'class' => $class, 'fields' => $importer->fields(), 'headers' => $sheet['headers'],
            'preview' => $preview, 'missing' => $missing,
            'account' => isset($import->mapping['__account']) ? BankAccount::find($import->mapping['__account']) : null,
        ]);
    }

    public function run(Request $request, Import $import, ImportRunner $runner): RedirectResponse
    {
        $this->typeClass($import->type);
        abort_unless($import->status === 'uploaded', 409);
        $import->update(['mapping' => $this->mappingFromRequest($request, $import)]);

        if ($import->total_rows > ImportRunner::SYNC_LIMIT) {
            $import->update(['status' => 'queued']);
            ProcessImport::dispatch($import->id, tenant()->id);

            return redirect()->route('imports.show', $import)->with('info', __('Fayl böyükdür (:v1 sətir) — import arxa planda aparılır. Səhifəni bir az sonra yeniləyin.', ['v1' => $import->total_rows]));
        }

        @set_time_limit(300);
        $runner->run($import);
        $import->refresh();

        return redirect()->route('imports.show', $import)->with($import->failed_rows ? 'warning' : 'success',
            __(':v1 sətir import edildi', ['v1' => $import->imported_rows]).($import->failed_rows ? __(', :v1 sətir xəta ilə qaytarıldı.', ['v1' => $import->failed_rows]) : '.'));
    }

    public function errors(Import $import): StreamedResponse
    {
        $this->typeClass($import->type);
        abort_unless($import->error_path && Storage::disk('local')->exists($import->error_path), 404);

        return Storage::disk('local')->download($import->error_path, 'xetalar-'.pathinfo($import->original_name, PATHINFO_FILENAME).'.xlsx');
    }

    private function mappingFromRequest(Request $request, Import $import): array
    {
        $importer = ImportRunner::importerFor($import);
        $map = [];
        foreach (array_keys($importer->fields()) as $field) {
            $v = $request->input('map.'.$field);
            if ($v !== null && $v !== '') {
                $map[$field] = (int) $v;
            }
        }
        if (isset($import->mapping['__account'])) {
            $map['__account'] = $import->mapping['__account'];
        }

        return $map;
    }

    /** Resolves the importer class and checks the user may run it. */
    private function typeClass(string $type): string
    {
        $class = collect(ImportRunner::TYPES)->first(fn ($c) => $c::type() === $type) ?? abort(404);
        $this->authorize($class::ability());

        return $class;
    }
}
