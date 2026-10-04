<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Support\Export\PdfExporter;
use App\Support\Export\SpreadsheetExporter;
use App\Rules\SpreadsheetFile;
use App\Support\Invoices\SupplierInvoiceSheet;
use App\Tables\Column;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function template(): Response
    {
        $book = SupplierInvoiceSheet::template();

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'glaust-satici-faktura-sablon.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * The seller's proforma from Excel -> supplier invoice(s) of this deal, bound to the
     * deal's purchase contract. All-or-nothing: any bad row and nothing is saved.
     */
    public function import(Request $request, Deal $deal, CurrencyRates $rates): RedirectResponse
    {
        $this->authorize('projects.create');
        $request->validate([
            'file' => ['required', 'file', 'max:10240', new SpreadsheetFile],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['file' => __('Excel faylı'), 'invoice_date' => __('Faktura tarixi')]);

        if (! $deal->purchase_contract_id || ! $deal->supplier_id) {
            return back()->with('error', __('Satıcının fakturası alış müqaviləsinə bağlanır: əvvəlcə Trade-də «Məhsulu satan tərəf» və onun müqaviləsini seçin.'));
        }

        try {
            $parsed = SupplierInvoiceSheet::parse($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('Fayl oxunmadı. TradeFlow şablonundan və ya .xlsx formatından istifadə edin.'));
        }
        if ($parsed['errors']) {
            return back()->with('import_errors', $parsed['errors'])->with('error', count($parsed['errors']).__(' sətirdə xəta var — heç nə yadda saxlanmadı. Faylı düzəldib yenidən yükləyin.'));
        }

        $existing = $deal->invoices()->where('type', 'supplier')->whereIn('number', array_map('strval', array_keys($parsed['invoices'])))->pluck('number')->all();
        if ($existing) {
            return back()->with('error', __('Bu proforma(lar) artıq bu Trade-də var: ').implode(', ', $existing).__('. Təkrar import edilmədi.'));
        }

        $currency = 'EUR'; // the sheet's money column is Total/EUR
        try {
            $rate = $rates->rate($currency, $request->input('invoice_date'));
        } catch (RateUnavailable $e) {
            return back()->with('error', $e->getMessage().__(' Faktura yadda saxlanmadı.'));
        }

        $created = DB::transaction(function () use ($parsed, $deal, $request, $currency, $rate) {
            $out = [];
            foreach ($parsed['invoices'] as $number => $items) {
                $total = round(array_sum(array_column($items, 'total')), 2);
                $invoice = $deal->invoices()->create([
                    'project_id' => $deal->project_id, 'type' => 'supplier', 'number' => (string) $number,
                    'invoice_date' => $request->input('invoice_date'), 'counterparty_id' => $deal->supplier_id,
                    'contract_id' => $deal->purchase_contract_id, 'currency' => $currency, 'total' => $total,
                    'cbar_rate' => $rate, 'total_azn' => round($total * $rate, 2), 'status' => 'draft',
                    'source_file' => mb_substr($request->file('file')->getClientOriginalName(), 0, 190),
                    'created_by' => $request->user()->id,
                ]);
                $invoice->items()->createMany($items);
                $out[] = $invoice;
            }
            // Keep the uploaded file with the first invoice.
            $file = $request->file('file');
            $path = $file->storeAs('attachments/'.tenant()->id.'/'.now()->format('Y/m'), Str::uuid().'.'.$file->extension(), 'local');
            $out[0]->attachments()->create(['original_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'path' => $path,
                'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
            if (in_array($deal->status, ['draft', 'active'], true)) {
                $deal->update(['status' => 'invoiced']);
            }

            return $out;
        });

        $lines = array_sum(array_map('count', $parsed['invoices']));
        $msg = count($created) === 1
            ? __('Faktura :v1 import edildi: :v2 sətir, ', ['v1' => $created[0]->number, 'v2' => $lines]).money($created[0]->total, $currency).'.'
            : count($created).__(' faktura import edildi (:v1 sətir).', ['v1' => $lines]);

        return redirect()->route(count($created) === 1 ? 'invoices.show' : 'deals.show', count($created) === 1 ? $created[0] : $deal)->with('success', $msg);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['deal', 'project', 'counterparty', 'contract', 'items', 'creator', 'attachments.uploader', 'salesDocuments']);
        $invoice->items->each->setRelation('invoice', $invoice);
        $history = AuditLog::with('user')->where('auditable_type', 'invoice')->where('auditable_id', $invoice->id)->latest('created_at')->limit(15)->get();

        return view('invoices.show', ['invoice' => $invoice, 'history' => $history, 'columns' => SupplierInvoiceSheet::COLUMNS]);
    }

    public function status(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Invoice::STATUSES))]]);
        $invoice->update($data);

        return back()->with('success', 'Status: '.Invoice::STATUSES[$data['status']][0]);
    }

    /**
     * Logistics cost after the import: forecast or actual; one amount split by Total share,
     * or an amount per line — all lines in one currency.
     */
    public function logistics(Request $request, Invoice $invoice, \App\Support\Invoices\LogisticsAllocator $allocator): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        if ($invoice->status === 'cancelled') {
            return back()->with('error', __('Ləğv edilmiş fakturaya xərc əlavə olunmur.'));
        }
        $request->merge([
            'logistics_amount' => parse_number($request->input('logistics_amount')),
            'items' => array_map(fn ($v) => $v === null || $v === '' ? null : parse_number($v), (array) $request->input('items', [])),
        ]);
        $currencies = Rule::in(config('glaust.currencies'));
        $data = $request->validate([
            'logistics_mode' => ['required', Rule::in(array_keys(Invoice::LOGISTICS_MODES))],
            'logistics_method' => ['required', Rule::in(array_keys(Invoice::LOGISTICS_METHODS))],
            'logistics_currency' => ['required_if:logistics_method,total', 'nullable', $currencies],
            'logistics_amount' => ['required_if:logistics_method,total', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'items' => ['required_if:logistics_method,per_item', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'item_currency' => ['required_if:logistics_method,per_item', 'array'],
            'item_currency.*' => ['nullable', $currencies],
        ], [], ['logistics_mode' => __('Xərcin növü'), 'logistics_method' => __('Bölüşdürmə üsulu'), 'logistics_currency' => __('Valyuta'), 'logistics_amount' => __('Logistika xərci')]);

        $currency = $data['logistics_currency'] ?? null;
        if ($data['logistics_method'] === 'per_item') {
            $used = array_values(array_unique(array_filter((array) ($data['item_currency'] ?? []))));
            if (count($used) !== 1) {
                return back()->withInput()->withErrors(['item_currency' => count($used) ? __('Bütün məhsullar üzrə xərc eyni valyutada olmalıdır (seçilib: ').implode(', ', $used).').' : __('Valyutanı seçin.')]);
            }
            $currency = $used[0];
        }

        $allocator->apply($invoice, $data['logistics_mode'], $data['logistics_method'], $currency,
            isset($data['logistics_amount']) ? (float) $data['logistics_amount'] : null, $data['items'] ?? []);
        $invoice->refresh();
        $this->documentsReady($invoice);

        return back()->with('success', __('Logistika xərci (').__(Invoice::LOGISTICS_MODES[$invoice->logistics_mode]).__(') bölüşdürüldü: ')
            .money($invoice->logistics_amount, $invoice->logistics_currency)
            .($invoice->logistics_currency !== $invoice->currency ? ' = '.money($invoice->logistics_total, $invoice->currency) : '').'.');
    }

    public function clearLogistics(Invoice $invoice, \App\Support\Invoices\LogisticsAllocator $allocator): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        $allocator->clear($invoice);

        return back()->with('success', __('Logistika xərci silindi.'));
    }

    /** Our commission %: applied to every line (Total × %), before or after the logistics cost. */
    public function commission(Request $request, Invoice $invoice, \App\Support\Invoices\CommissionCalculator $calculator): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        if ($invoice->status === 'cancelled') {
            return back()->with('error', __('Ləğv edilmiş fakturaya komissiya tətbiq olunmur.'));
        }
        $request->merge(['commission_rate' => parse_number(str_replace('%', '', (string) $request->input('commission_rate')))]);
        $data = $request->validate([
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ], [], ['commission_rate' => __('Komissiya faizi')]);

        $calculator->apply($invoice, (float) $data['commission_rate']);
        $invoice->refresh();
        $this->documentsReady($invoice);

        return back()->with('success', __('Komissiya ').$invoice->commissionLabel().__('% tətbiq olundu: ').money($invoice->commission_total, $invoice->currency).'.');
    }

    public function clearCommission(Invoice $invoice, \App\Support\Invoices\CommissionCalculator $calculator): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        $calculator->clear($invoice);

        return back()->with('success', __('Komissiya silindi.'));
    }

    /**
     * Invoice currency -> RUB for the RUR columns, for the date our invoice will be issued:
     * the CBAR rate of that day, or a forecast rate for a day CBAR has not published yet.
     */
    public function rub(Request $request, Invoice $invoice, \App\Support\Invoices\RubConverter $converter): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        if ($invoice->status === 'cancelled') {
            return back()->with('error', __('Ləğv edilmiş fakturada çevirmə edilmir.'));
        }
        $request->merge(['fx_base_azn' => parse_number($request->input('fx_base_azn')), 'fx_target_azn' => parse_number($request->input('fx_target_azn'))]);
        $data = $request->validate([
            'fx_source' => ['required', Rule::in(array_keys(\App\Support\Invoices\RubConverter::SOURCES))],
            'fx_date' => ['required', 'date', Rule::when($request->input('fx_source') === 'cbar', ['before_or_equal:today'])],
            'fx_base_azn' => ['required_if:fx_source,forecast', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
            'fx_target_azn' => ['required_if:fx_source,forecast', 'nullable', 'numeric', 'gt:0', 'max:1000000'],
        ], ['fx_date.before_or_equal' => __('Gələcək tarix üçün CBAR kursu hələ dərc olunmayıb — «Proqnoz» seçin.')],
            ['fx_source' => __('Kurs mənbəyi'), 'fx_date' => __('Faktura tarixi'), 'fx_base_azn' => __('Proq ').$invoice->currency, 'fx_target_azn' => __('Proq RUB')]);

        $forecast = $data['fx_source'] === 'forecast';
        $converter->apply($invoice, $data['fx_source'], $data['fx_date'],
            $forecast ? (float) $data['fx_base_azn'] : null, $forecast ? (float) $data['fx_target_azn'] : null);
        $invoice->refresh();
        $this->documentsReady($invoice);

        return back()->with('success', __(\App\Support\Invoices\RubConverter::SOURCES[$invoice->fx_source]).__(' tətbiq olundu: 1 ').$invoice->currency.' = '.num($invoice->fx_rate, 4).' RUB ('.azdate($invoice->fx_date).').');
    }

    public function clearRub(Invoice $invoice, \App\Support\Invoices\RubConverter $converter): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        $converter->clear($invoice);

        return back()->with('success', __('RUB çevirməsi silindi.'));
    }

    private function lockedMessage(Invoice $invoice): string
    {
        return $invoice->isApproved()
            ? __('Faktura təsdiqlənib — düzəliş əməliyyatlarına icazə dayandırılıb.')
            : __('Faktura təsdiqdədir — təsdiq bitənə və ya sorğu geri çəkilənə qədər düzəliş edilmir.');
    }

    /** Create the buyer's proforma + specification the first time the calculation is complete. */
    private function documentsReady(Invoice $invoice): void
    {
        $created = app(\App\Support\Invoices\SalesDocumentBuilder::class)->ensureFor($invoice);
        if ($created) {
            session()->flash('documents_created', collect($created)->map(fn ($d) => $d->title().' '.$d->number)->all());
        }
    }

    /** Recreate a deleted proforma/specification from the current calculation. */
    public function documents(Invoice $invoice, \App\Support\Invoices\SalesDocumentBuilder $builder): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        if (! $invoice->rubReady()) {
            return back()->with('error', __('Əvvəlcə 3 addımı tamamlayın: logistika, komissiya, RUB konvertasiyası.'));
        }
        $created = $builder->ensureFor($invoice);

        return back()->with('success', $created ? __('Yaradıldı: ').collect($created)->map(fn ($d) => $d->title().' '.$d->number)->implode(', ').'.' : __('Sənədlər artıq mövcuddur.'));
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('projects.delete');
        if ($invoice->isLocked()) {
            return back()->with('error', $this->lockedMessage($invoice));
        }
        $deal = $invoice->deal_id;
        $invoice->delete();

        return redirect()->route('deals.show', $deal)->with('success', __('Faktura :v1 silindi.', ['v1' => $invoice->number]));
    }

    public function export(Request $request, Invoice $invoice): Response
    {
        $invoice->load('items', 'deal', 'counterparty');
        $invoice->items->each->setRelation('invoice', $invoice);
        $rub = $invoice->rubReady();
        $cols = [];
        foreach (SupplierInvoiceSheet::COLUMNS as $key => [$label, $fillable]) {
            $both = $invoice->hasLogistics() && $invoice->hasCommission();
            $computed = match (true) {
                $key === 'logistics' && $invoice->hasLogistics() => Column::make($label.' ('.$invoice->currency.')', 'logistics', 'money', total: true),
                $key === 'unit_price_log' && $invoice->hasLogistics() => Column::make($label, fn ($it) => $it->unitPriceLog(), 'money'),
                $key === 'fee' && $invoice->hasCommission() => Column::make('Commission '.$invoice->commissionLabel().'%', 'commission', 'money', total: true),
                $key === 'unit_price_ccl_eur' && $both => Column::make($label, fn ($it) => $it->unitPriceCcl(), 'money'),
                $key === 'total_ccl_eur' && $both => Column::make($label, fn ($it) => $it->totalCcl(), 'money', total: true),
                $key === 'unit_price_rur' && $rub => Column::make($label, fn ($it) => $it->unitPriceRub(), 'money'),
                $key === 'total_rur' && $rub => Column::make($label, fn ($it) => $it->totalRub(), 'money', total: true),
                $key === 'unit_price_rur_rounded' && $rub => Column::make($label.' (yuvarlaq)', fn ($it) => $it->unitPriceRubRounded(), 'money'),
                $key === 'total_rur_rounded' && $rub => Column::make($label.' (yuvarlaq)', fn ($it) => $it->totalRubRounded(), 'money', total: true),
                default => null,
            };
            if ($computed) {
                $cols[] = $computed;

                continue;
            }
            if (! $fillable) {
                continue; // computed columns whose inputs are not entered yet
            }
            $cols[] = match ($key) {
                'proforma' => Column::make($label, fn () => $invoice->number),
                'line_no' => Column::make($label, 'line_no', 'number'),
                'quantity' => Column::make($label, 'quantity', 'number', total: true),
                'unit_price' => Column::make($label, 'unit_price', 'rate'),
                'total' => Column::make($label, 'total', 'money', total: true),
                default => Column::make($label, $key),
            };
        }
        $title = $invoice->typeLabel().' '.$invoice->number;
        $filters = [($invoice->counterparty?->name ?? '').' · '.azdate($invoice->invoice_date).' · Trade '.$invoice->deal?->code];
        $name = 'faktura-'.Str::slug($invoice->number).'-'.now()->format('Y-m-d');

        return $request->query('format') === 'pdf'
            ? app(PdfExporter::class)->download($title, $cols, $invoice->items, $name.'.pdf', $filters)
            : app(SpreadsheetExporter::class)->download($title, $cols, $invoice->items, $name.'.xlsx', $filters);
    }
}
