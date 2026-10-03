<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Support\Export\PdfExporter;
use App\Support\Export\SpreadsheetExporter;
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
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['file' => 'Excel faylı', 'invoice_date' => 'Faktura tarixi']);

        if (! $deal->purchase_contract_id || ! $deal->supplier_id) {
            return back()->with('error', 'Satıcının fakturası alış müqaviləsinə bağlanır: əvvəlcə tədarükdə «Məhsulu satan tərəf» və onun müqaviləsini seçin.');
        }

        try {
            $parsed = SupplierInvoiceSheet::parse($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Fayl oxunmadı. Glaust şablonundan və ya .xlsx formatından istifadə edin.');
        }
        if ($parsed['errors']) {
            return back()->with('import_errors', $parsed['errors'])->with('error', count($parsed['errors']).' sətirdə xəta var — heç nə yadda saxlanmadı. Faylı düzəldib yenidən yükləyin.');
        }

        $existing = $deal->invoices()->where('type', 'supplier')->whereIn('number', array_map('strval', array_keys($parsed['invoices'])))->pluck('number')->all();
        if ($existing) {
            return back()->with('error', 'Bu proforma(lar) artıq bu tədarükdə var: '.implode(', ', $existing).'. Təkrar import edilmədi.');
        }

        $currency = 'EUR'; // the sheet's money column is Total/EUR
        try {
            $rate = $rates->rate($currency, $request->input('invoice_date'));
        } catch (RateUnavailable $e) {
            return back()->with('error', $e->getMessage().' Faktura yadda saxlanmadı.');
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
            ? "Faktura {$created[0]->number} import edildi: {$lines} sətir, ".money($created[0]->total, $currency).'.'
            : count($created)." faktura import edildi ({$lines} sətir).";

        return redirect()->route(count($created) === 1 ? 'invoices.show' : 'deals.show', count($created) === 1 ? $created[0] : $deal)->with('success', $msg);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['deal', 'project', 'counterparty', 'contract', 'items', 'creator', 'attachments.uploader']);
        $history = AuditLog::with('user')->where('auditable_type', 'invoice')->where('auditable_id', $invoice->id)->latest('created_at')->limit(15)->get();

        return view('invoices.show', ['invoice' => $invoice, 'history' => $history, 'columns' => SupplierInvoiceSheet::COLUMNS]);
    }

    public function status(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('projects.update');
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
        if ($invoice->status === 'cancelled') {
            return back()->with('error', 'Ləğv edilmiş fakturaya xərc əlavə olunmur.');
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
        ], [], ['logistics_mode' => 'Xərcin növü', 'logistics_method' => 'Bölüşdürmə üsulu', 'logistics_currency' => 'Valyuta', 'logistics_amount' => 'Logistika xərci']);

        $currency = $data['logistics_currency'] ?? null;
        if ($data['logistics_method'] === 'per_item') {
            $used = array_values(array_unique(array_filter((array) ($data['item_currency'] ?? []))));
            if (count($used) !== 1) {
                return back()->withInput()->withErrors(['item_currency' => count($used) ? 'Bütün məhsullar üzrə xərc eyni valyutada olmalıdır (seçilib: '.implode(', ', $used).').' : 'Valyutanı seçin.']);
            }
            $currency = $used[0];
        }

        $allocator->apply($invoice, $data['logistics_mode'], $data['logistics_method'], $currency,
            isset($data['logistics_amount']) ? (float) $data['logistics_amount'] : null, $data['items'] ?? []);
        $invoice->refresh();

        return back()->with('success', 'Logistika xərci ('.Invoice::LOGISTICS_MODES[$invoice->logistics_mode].') bölüşdürüldü: '
            .money($invoice->logistics_amount, $invoice->logistics_currency)
            .($invoice->logistics_currency !== $invoice->currency ? ' = '.money($invoice->logistics_total, $invoice->currency) : '').'.');
    }

    public function clearLogistics(Invoice $invoice, \App\Support\Invoices\LogisticsAllocator $allocator): RedirectResponse
    {
        $this->authorize('projects.update');
        $allocator->clear($invoice);

        return back()->with('success', 'Logistika xərci silindi.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('projects.delete');
        $deal = $invoice->deal_id;
        $invoice->delete();

        return redirect()->route('deals.show', $deal)->with('success', "Faktura {$invoice->number} silindi.");
    }

    public function export(Request $request, Invoice $invoice): Response
    {
        $invoice->load('items', 'deal', 'counterparty');
        $cols = [];
        foreach (SupplierInvoiceSheet::COLUMNS as $key => [$label, $fillable]) {
            if ($key === 'logistics' && $invoice->hasLogistics()) {
                $cols[] = Column::make($label.' ('.$invoice->currency.')', 'logistics', 'money', total: true);

                continue;
            }
            if (! $fillable) {
                continue; // later-stage columns: rules not defined yet
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
        $filters = [($invoice->counterparty?->name ?? '').' · '.azdate($invoice->invoice_date).' · Tədarük '.$invoice->deal?->code];
        $name = 'faktura-'.Str::slug($invoice->number).'-'.now()->format('Y-m-d');

        return $request->query('format') === 'pdf'
            ? app(PdfExporter::class)->download($title, $cols, $invoice->items, $name.'.pdf', $filters)
            : app(SpreadsheetExporter::class)->download($title, $cols, $invoice->items, $name.'.xlsx', $filters);
    }
}
