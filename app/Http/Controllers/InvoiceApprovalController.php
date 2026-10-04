<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Send a calculated invoice for approval, decide on it, or take the request back. */
class InvoiceApprovalController extends Controller
{
    public function __construct(private InvoiceApprovalService $approvals) {}

    public function submit(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('projects.update');
        $this->approvals->submit($invoice, $request->user());
        $first = $invoice->fresh()->approval_flow[0] ?? null;

        return back()->with('success', __('Faktura təsdiqə göndərildi').($first ? ': '.(\App\Models\User::forTenant()->find($first['user_id'])?->name ?? '') : '').__('. Təsdiq bitənə qədər düzəlişlər dayandırılıb.'));
    }

    /** Only the approver of the current step can decide; anyone else is refused by the service. */
    public function decide(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $approve = $data['decision'] === 'approve';
        $this->approvals->decide($invoice, $request->user(), $approve, $data['comment'] ?? null);
        $invoice->refresh();

        return back()->with('success', match (true) {
            ! $approve => __('Faktura geri qaytarıldı; göndərən şəxsə bildiriş getdi.'),
            $invoice->isApproved() => __('Faktura təsdiqləndi. Kommersiya fakturası hazırdır.'),
            default => __('Təsdiqləndi; növbəti şəxsə göndərildi.'),
        });
    }

    public function withdraw(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->approvals->withdraw($invoice, $request->user());

        return back()->with('success', __('Təsdiq sorğusu geri çəkildi; faktura yenidən redaktə edilə bilər.'));
    }
}
