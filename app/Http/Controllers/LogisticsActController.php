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
        $request->merge(['amount' => parse_number($request->input('amount'))]);
        $data = $request->validate([
            'counterparty_id' => ['nullable', 'integer', TenantExists::in('counterparties')],
            'invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('deal_id', $deal->id)->where('company_id', tenant()->id)],
            'act_number' => ['required', 'string', 'max:64'],
            'act_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'logistics_invoice_number' => ['nullable', 'string', 'max:64'],
            'logistics_invoice_date' => ['nullable', 'date'],
            'payment_plan' => ['required', Rule::in(['today', 'later'])],
            'planned_date' => ['required_if:payment_plan,later', 'nullable', 'date', 'after_or_equal:today'],
            'remind' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['planned_date.required_if' => __('Köçürmə tarixini seçin.')], [
            'act_number' => __('Akt nömrəsi'), 'act_date' => __('Akt tarixi'), 'amount' => __('Məbləğ'), 'currency' => __('Valyuta'), 'planned_date' => __('Köçürmə tarixi'),
            'counterparty_id' => __('Logistika şirkəti'),
        ]);
        $parts = $data['payment_plan'] === 'today' ? $this->parts($request) : [];
        if ($parts) {
            $this->authorize('bank.create');
        }
        $act = $this->logistics->createAct($deal, $data, $parts);

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', __('Logistika aktı ').$act->act_number.__(' əlavə edildi')
            .($act->payments->isNotEmpty() ? __(' və ödənildi (').$act->payments->count().__(' hissə).') : ($act->reminder_id ? '; '.azdate($act->planned_date).__(' üçün xatırlatma quruldu.') : '.')));
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

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', __('Akt ').$act->act_number.__(' üzrə ödəniş edildi (').count($paid).__(' hissə).'));
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

        return redirect()->route('deals.show', [$deal, 'tab' => 'logistics'])->with('success', __('Akt ').$act->act_number.__(' və onun ödənişləri silindi.'));
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
            foreach (['amount', 'act_amount', 'bank_rate', 'fee_amount'] as $k) {
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
            'parts.*.fee_amount' => ['nullable', 'numeric', 'min:0'],
            'parts.*.payment_date' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['parts.required' => __('Ən azı bir ödəniş hissəsi daxil edin.'), 'parts.*.payment_date.before_or_equal' => __('Hissənin ödəniş tarixi gələcək ola bilməz.')], [
            'parts.*.payment_date' => __('Ödəniş tarixi'),
            'parts.*.act_amount' => __('Hissənin məbləği'), 'parts.*.amount' => __('Ödənilən məbləğ'), 'parts.*.bank_account_id' => __('Bank hesabı'), 'parts.*.bank_rate' => __('Bankın kursu'),
        ])['parts'];
    }
}
