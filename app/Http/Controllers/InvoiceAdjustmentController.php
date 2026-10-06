<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Corrections of a seller invoice: added by hand, or the automatic one (from a commercial invoice correction) edited / removed. */
class InvoiceAdjustmentController extends Controller
{
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('projects.update');
        abort_unless($invoice->type === 'supplier', 404);
        $data = $this->validated($request);
        $invoice->adjustments()->create($data + ['deal_id' => $invoice->deal_id, 'currency' => $invoice->currency, 'created_by' => $request->user()->id]);

        return back()->with('success', __('Satıcı fakturasına düzəliş qeydə alındı.'));
    }

    public function update(Request $request, InvoiceAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('projects.update');
        $adjustment->update($this->validated($request));

        return back()->with('success', __('Düzəliş yeniləndi.'));
    }

    public function destroy(InvoiceAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('projects.update');
        $adjustment->delete();

        return back()->with('success', __('Düzəliş silindi.'));
    }

    private function validated(Request $request): array
    {
        $request->merge(['amount' => parse_number($request->input('amount'))]);

        return $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-999999999', 'max:999999999'],
            'adjustment_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => __('Məbləğ'), 'adjustment_date' => __('Tarix'), 'reason' => __('Səbəb')]);
    }
}
