<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Support\Export\PdfExporter;
use App\Support\Invoices\SalesDocumentBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's documents (proforma, specification): generated from the calculation, then every
 * field and line stays editable; the PDF is always rendered from the current state and every
 * save is kept in the audit history.
 */
class SalesDocumentController extends Controller
{
    public function show(SalesDocument $document, SalesDocumentBuilder $builder): View
    {
        $document->load(['deal.saleContract', 'project', 'sourceInvoice', 'counterparty', 'editor']);
        $history = AuditLog::with('user')->where('auditable_type', 'sales_document')->where('auditable_id', $document->id)->latest('created_at')->limit(25)->get();
        $sibling = SalesDocument::where('source_invoice_id', $document->source_invoice_id)->where('id', '!=', $document->id)->first();

        if ($document->isPacking()) {
            $proforma = SalesDocument::where('source_invoice_id', $document->source_invoice_id)->where('kind', 'proforma')->first();

            return view('sales-documents.packing', ['doc' => $document, 'history' => $history, 'proforma' => $proforma]);
        }

        return view('sales-documents.show', [
            'doc' => $document,
            'history' => $history,
            'sibling' => $sibling,
            'stale' => $builder->isStale($document),
        ]);
    }

    public function update(Request $request, SalesDocument $document, SalesDocumentBuilder $builder): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($document->isLocked()) {
            return back()->with('error', $document->kind === 'commercial' ? __('Kommersiya fakturası təsdiqdən sonra yaradılıb və dəyişdirilmir.') : __('Faktura təsdiqdədir və ya təsdiqlənib — sənəddə düzəliş əməliyyatlarına icazə dayandırılıb.'));
        }
        if ($document->isPacking()) {
            return $this->updatePacking($request, $document);
        }
        $lines = array_values(array_filter((array) $request->input('lines', []), fn ($l) => is_array($l) && trim(implode('', array_map('strval', $l))) !== ''));
        foreach ($lines as &$l) {
            $l['quantity'] = parse_number($l['quantity'] ?? null);
            $l['unit_price'] = parse_number($l['unit_price'] ?? null);
        }
        unset($l);
        $request->merge(['lines' => $lines, 'freight' => parse_number($request->input('freight')), 'insurance' => parse_number($request->input('insurance'))]);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:64'],
            'doc_date' => ['required', 'date'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'contract_date' => ['nullable', 'string', 'max:32'],
            'heading' => ['nullable', 'string', 'max:120'],
            'seller_block' => ['nullable', 'string', 'max:2000'],
            'customer_block' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'delivery_terms' => ['nullable', 'string', 'max:255'],
            'freight' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'insurance' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'seller_signatory' => ['nullable', 'string', 'max:255'],
            'buyer_signatory' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.hs_code' => ['nullable', 'string', 'max:32'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ], ['lines.min' => __('Sənəddə ən azı bir sətir olmalıdır.')], [
            'number' => __('Nömrə'), 'doc_date' => __('Tarix'), 'lines.*.description' => __('Təsvir'), 'lines.*.quantity' => __('Miqdar'), 'lines.*.unit_price' => __('Qiymət'),
        ]);

        $data['lines'] = array_map(fn ($l, $i) => [
            'n' => $i + 1,
            'description' => trim($l['description']),
            'hs_code' => trim((string) ($l['hs_code'] ?? '')),
            'uom' => trim((string) ($l['uom'] ?? '')),
            'quantity' => (float) $l['quantity'],
            'unit_price' => (float) $l['unit_price'],
            'total' => round((float) $l['quantity'] * (float) $l['unit_price'], 2),
        ], $data['lines'], array_keys($data['lines']));
        $data['total'] = $builder->total($data['lines'], $data['freight'] ?? null, $data['insurance'] ?? null);
        $data['updated_by'] = $request->user()->id;

        $document->update($data);

        return back()->with('success', $document->title().' '.$document->number.__(' yadda saxlanıldı. PDF son vəziyyətdən yaradılacaq.'));
    }

    /** Replace the lines with the current calculation (header fields are kept). */
    public function refresh(SalesDocument $document, SalesDocumentBuilder $builder): RedirectResponse
    {
        $this->authorize('projects.update');
        if ($document->isLocked()) {
            return back()->with('error', $document->kind === 'commercial' ? __('Kommersiya fakturası təsdiqdən sonra yaradılıb və dəyişdirilmir.') : __('Faktura təsdiqdədir və ya təsdiqlənib — sənəddə düzəliş əməliyyatlarına icazə dayandırılıb.'));
        }
        if (! in_array($document->kind, SalesDocument::AUTO_KINDS, true)) {
            return back()->with('error', __('Bu sənəd hesablamadan yenilənmir.'));
        }
        try {
            $document->revisionReason = __('Fakturanın hesablamasından yeniləndi');
            $builder->refreshLines($document);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Sətirlər hesablamadan yeniləndi.'));
    }

    public function pdf(Request $request, SalesDocument $document, PdfExporter $pdf): Response
    {
        $document->load('deal');
        $company = tenant();
        $logo = null;
        if ($company->logo_path && Storage::disk('local')->exists($company->logo_path)) {
            $mime = Storage::disk('local')->mimeType($company->logo_path);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($company->logo_path));
            }
        }
        $name = ['proforma' => 'Proforma ', 'specification' => 'Specification ', 'commercial' => 'Commercial Invoice ', 'packing' => 'Packing List '][$document->kind].Str::slug($document->number).'.pdf';

        return $pdf->render('pdf.'.$document->kind, ['doc' => $document, 'logo' => $logo], $name, false, $request->boolean('inline'));
    }

    /** Packing list: header + pallets, each with its items and weights. */
    private function updatePacking(Request $request, SalesDocument $document): RedirectResponse
    {
        $num = fn ($v) => $v === null || trim((string) $v) === '' ? null : parse_number($v);
        $pallets = [];
        foreach (array_values((array) $request->input('pallets', [])) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $items = [];
            foreach (array_values((array) ($p['items'] ?? [])) as $it) {
                if (! is_array($it) || trim((string) ($it['description'] ?? '').(string) ($it['code'] ?? '')) === '') {
                    continue;
                }
                $items[] = ['code' => trim((string) ($it['code'] ?? '')), 'description' => trim((string) ($it['description'] ?? '')), 'package' => trim((string) ($it['package'] ?? '')),
                    'quantity' => $num($it['quantity'] ?? null), 'qty_unit' => trim((string) ($it['qty_unit'] ?? '')),
                    'total' => $num($it['total'] ?? null), 'total_unit' => trim((string) ($it['total_unit'] ?? '')), 'weight' => $num($it['weight'] ?? null)];
            }
            $pallets[] = ['title' => trim((string) ($p['title'] ?? '')), 'packing' => trim((string) ($p['packing'] ?? '')), 'weight' => $num($p['weight'] ?? null), 'items' => $items];
        }
        $request->merge(['pallets' => $pallets]);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:64'],
            'doc_date' => ['required', 'date'],
            'contract_number' => ['nullable', 'string', 'max:64'],
            'contract_date' => ['nullable', 'string', 'max:32'],
            'heading' => ['nullable', 'string', 'max:120'],
            'seller_block' => ['nullable', 'string', 'max:2000'],
            'customer_block' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'seller_signatory' => ['nullable', 'string', 'max:255'],
            'pallets' => ['required', 'array', 'min:1', 'max:500'],
            'pallets.*.title' => ['required', 'string', 'max:60'],
            'pallets.*.packing' => ['nullable', 'string', 'max:120'],
            'pallets.*.weight' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'pallets.*.items' => ['required', 'array', 'min:1', 'max:100'],
            'pallets.*.items.*.code' => ['nullable', 'string', 'max:40'],
            'pallets.*.items.*.description' => ['required', 'string', 'max:300'],
            'pallets.*.items.*.package' => ['nullable', 'string', 'max:40'],
            'pallets.*.items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'pallets.*.items.*.qty_unit' => ['nullable', 'string', 'max:16'],
            'pallets.*.items.*.total' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'pallets.*.items.*.total_unit' => ['nullable', 'string', 'max:16'],
            'pallets.*.items.*.weight' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ], ['pallets.min' => __('Ən azı bir palet olmalıdır.'), 'pallets.*.items.required' => __('Hər paletdə ən azı bir məhsul olmalıdır.')], [
            'number' => __('Nömrə'), 'doc_date' => __('Tarix'), 'pallets.*.title' => __('Paletin adı'), 'pallets.*.items.*.description' => __('Təsvir'),
            'pallets.*.weight' => __('Paletlə çəki'), 'pallets.*.items.*.weight' => __('Qablaşdırma ilə çəki'),
        ]);
        $data['lines'] = $data['pallets'];
        unset($data['pallets']);
        $data['total'] = 0;
        $data['updated_by'] = $request->user()->id;
        $document->update($data);

        return back()->with('success', 'Packing List '.$document->number.__(' yadda saxlanıldı. PDF son vəziyyətdən yaradılacaq.'));
    }

    public function destroy(SalesDocument $document): RedirectResponse
    {
        $this->authorize('projects.delete');
        if ($document->isLocked()) {
            return back()->with('error', $document->kind === 'commercial' ? __('Kommersiya fakturası təsdiqdən sonra yaradılıb və dəyişdirilmir.') : __('Faktura təsdiqdədir və ya təsdiqlənib — sənəddə düzəliş əməliyyatlarına icazə dayandırılıb.'));
        }
        $deal = $document->deal_id;
        $document->delete();

        return redirect()->route('deals.show', $deal)->with('success', $document->title().' '.$document->number.__(' silindi. Fakturadan yenidən yaratmaq olar.'));
    }
}
