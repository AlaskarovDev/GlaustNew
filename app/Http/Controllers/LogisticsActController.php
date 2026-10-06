<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Rules\TenantExists;
use App\Services\LogisticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Logistika tab: acts of the logistics company and their (split) payments. */
class LogisticsActController extends Controller
{
    public function __construct(private LogisticsService $logistics) {}

    public function store(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('projects.update');
        $request->merge(['amount' => parse_number($request->input('amount')), 'has_act' => $request->boolean('has_act')]);
        $data = $request->validate([
            'counterparty_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('deal_id', $deal->id)->where('company_id', tenant()->id)],
            'logistics_invoice_number' => ['required', 'string', 'max:64'],
            'logistics_invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'has_act' => ['boolean'],
            'act_number' => ['required_if:has_act,true', 'nullable', 'string', 'max:64'],
            'act_date' => ['required_if:has_act,true', 'nullable', 'date', 'before_or_equal:today'],
            'act_file' => ['nullable', 'file', 'max:'.config('glaust.upload.max_kb'), 'mimes:pdf,jpg,jpeg,png'],
            // invoice = paid on the invoice date; later = another date (past/today: paid now, future: planned)
            'payment_plan' => ['required', Rule::in(['invoice', 'today', 'later'])],
            'planned_date' => ['required_if:payment_plan,later', 'nullable', 'date'],
            'remind' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'planned_date.required_if' => __('Köçürmə tarixini seçin.'),
            'act_number.required_if' => __('Akt nömrəsini yazın.'), 'act_date.required_if' => __('Akt tarixini seçin.'),
        ], [
            'logistics_invoice_number' => __('Invoys nömrəsi'), 'logistics_invoice_date' => __('Invoys tarixi'),
            'act_number' => __('Akt nömrəsi'), 'act_date' => __('Akt tarixi'), 'act_file' => __('Aktın sənədi'),
            'amount' => __('Məbləğ'), 'currency' => __('Valyuta'), 'planned_date' => __('Köçürmə tarixi'), 'counterparty_id' => __('Logistika şirkəti'),
        ]);
        if (! $data['has_act']) {
            $data['act_number'] = $data['act_date'] = null;
        }
        $payDate = $data['payment_plan'] === 'later' ? $data['planned_date'] : $data['logistics_invoice_date'];
        $payNow = $payDate <= today()->toDateString();
        $data['payment_plan'] = $payNow ? 'today' : 'later';
        $data['planned_date'] = $payNow ? null : $payDate;

        $parts = $payNow ? $this->parts($request) : [];
        if ($parts) {
            $this->authorize('bank.create');
        }
        $act = $this->logistics->createAct($deal, $data, $parts, $payDate);
        if ($data['has_act']) {
            $this->storeActFile($request, $act);
        }

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', __('Logistika: :doc əlavə edildi', ['doc' => $act->label()])
            .($act->payments->isNotEmpty() ? __(' və ödənildi (').$act->payments->count().__(' hissə).') : ($act->reminder_id ? '; '.azdate($act->planned_date).__(' üçün xatırlatma quruldu.') : '.')));
    }

    /** The act came later: its number, date and scanned copy for an invoice saved without one. */
    public function attachAct(Request $request, Deal $deal, LogisticsAct $act): RedirectResponse
    {
        $this->authorize('projects.update');
        abort_unless($act->deal_id === $deal->id, 404);
        $data = $request->validate([
            'act_number' => ['required', 'string', 'max:64'],
            'act_date' => ['required', 'date', 'before_or_equal:today'],
            'act_file' => ['nullable', 'file', 'max:'.config('glaust.upload.max_kb'), 'mimes:pdf,jpg,jpeg,png'],
        ], [], ['act_number' => __('Akt nömrəsi'), 'act_date' => __('Akt tarixi'), 'act_file' => __('Aktın sənədi')]);
        $act->update(['act_number' => $data['act_number'], 'act_date' => $data['act_date']]);
        $this->storeActFile($request, $act);

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', __('Akt :v1 əlavə edildi.', ['v1' => $data['act_number']]));
    }

    private function storeActFile(Request $request, LogisticsAct $act): void
    {
        if (! $request->hasFile('act_file')) {
            return;
        }
        $file = $request->file('act_file');
        $act->attachments()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'path' => $file->storeAs('attachments/'.tenant()->id.'/'.now()->format('Y/m'), \Illuminate\Support\Str::uuid().'.'.$file->extension(), 'local'),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => $request->user()->id,
        ]);
    }

    public function pay(Request $request, Deal $deal, LogisticsAct $act): RedirectResponse
    {
        $this->authorize('bank.create');
        abort_unless($act->deal_id === $deal->id, 404);
        $data = $request->validate([
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:80'],
        ], [], ['payment_date' => __('Köçürmə tarixi')]);
        $paid = $this->logistics->pay($act, $this->parts($request), $data['payment_date'], $data['reference'] ?? null);

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', $act->label().__(' üzrə ödəniş edildi (').count($paid).__(' hissə).'));
    }

    public function remind(Request $request, Deal $deal, LogisticsAct $act): RedirectResponse
    {
        $this->authorize('projects.update');
        abort_unless($act->deal_id === $deal->id, 404);
        $data = $request->validate(['planned_date' => ['required', 'date', 'after_or_equal:today']], [], ['planned_date' => __('Köçürmə tarixi')]);
        $act->update(['planned_date' => $data['planned_date'], 'payment_plan' => 'later']);
        $this->logistics->remind($act->fresh());

        return back()->with('success', azdate($data['planned_date']).__(' üçün xatırlatma quruldu.'));
    }

    public function destroy(Deal $deal, LogisticsAct $act): RedirectResponse
    {
        $this->authorize('projects.delete');
        abort_unless($act->deal_id === $deal->id, 404);
        $this->logistics->deleteAct($act);

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', $act->label().__(' və onun ödənişləri silindi.'));
    }

    public function destroyPayment(Deal $deal, LogisticsPayment $payment): RedirectResponse
    {
        $this->authorize('bank.delete');
        abort_unless($payment->deal_id === $deal->id, 404);
        $this->logistics->deletePayment($payment);

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', __('Ödəniş hissəsi ləğv edildi; hesabdan silinmə və komissiya da silindi.'));
    }

    /** parts[i][act_amount|currency|bank_account_id|bank_rate|fee_amount] -> validated list */
    private function parts(Request $request): array
    {
        $parts = array_values(array_filter((array) $request->input('parts', []), fn ($p) => is_array($p) && (trim((string) ($p['amount'] ?? '')) !== '' || trim((string) ($p['act_amount'] ?? '')) !== '')));
        foreach ($parts as &$p) {
            foreach (['amount', 'act_amount', 'bank_rate', 'bank_rate_azn', 'fee_amount', 'fee_bank_rate'] as $k) {
                $p[$k] = isset($p[$k]) && $p[$k] !== '' ? parse_number($p[$k]) : null;
            }
        }
        unset($p);
        $request->merge(['parts' => $parts]);

        return $request->validate([
            'parts' => ['required', 'array', 'min:1', 'max:10'],
            // amount = paid in the part's currency (the form); act_amount = share of the act (also accepted)
            'parts.*.amount' => ['required_without:parts.*.act_amount', 'nullable', 'numeric', 'gt:0'],
            'parts.*.act_amount' => ['required_without:parts.*.amount', 'nullable', 'numeric', 'gt:0'],
            'parts.*.currency' => ['required', Rule::in(config('glaust.currencies'))],
            'parts.*.bank_account_id' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'parts.*.bank_rate' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
            'parts.*.bank_rate_azn' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],   // typed as 1 AZN = ? RUB
            'parts.*.fee_amount' => ['nullable', 'numeric', 'min:0'],
            'parts.*.fee_account_id' => ['nullable', 'integer', TenantExists::in('bank_accounts')],
            'parts.*.fee_bank_rate' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
            'parts.*.payment_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['parts.required' => __('Ən azı bir ödəniş hissəsi daxil edin.'), 'parts.*.payment_date.before_or_equal' => __('Hissənin ödəniş tarixi gələcək ola bilməz.')], [
            'parts.*.payment_date' => __('Ödəniş tarixi'),
            'parts.*.act_amount' => __('Hissənin məbləği'), 'parts.*.amount' => __('Ödənilən məbləğ'), 'parts.*.bank_account_id' => __('Bank hesabı'), 'parts.*.bank_rate' => __('Bankın kursu'),
        ])['parts'];
    }
}
