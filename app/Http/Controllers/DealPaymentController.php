<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Deal;
use App\Rules\TenantExists;
use App\Services\BankLedger;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mədaxillər: money the buyer sends us for a deal. Recorded as an incoming bank movement on
 * our account (so it also appears in Bank), valued at the CBAR rate of the date and at the
 * rate the bank actually applied — the bank's rate is entered by hand because it differs from CBAR.
 */
class DealPaymentController extends Controller
{
    public function __construct(private BankLedger $ledger) {}

    public function store(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('bank.create');
        if ($request->input('direction') === 'out') {
            return $this->paySupplier($request, $deal);
        }
        // The buyer pays us.
        $out = false;
        $partyId = $out ? $deal->supplier_id : $deal->counterparty_id;
        if (! $partyId) {
            return back()->with('error', $out ? __('Əvvəlcə Trade-də satıcını seçin.') : __('Əvvəlcə Trade-də alıcını (məhsulu satdığımız tərəfi) seçin.'));
        }
        $request->merge(['amount' => parse_number($request->input('amount')), 'applied_rate' => parse_number($request->input('applied_rate'))]);
        $data = $request->validate([
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'bank_account_id' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'applied_rate' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'reference' => ['nullable', 'string', 'max:80'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ], [], ['transaction_date' => __('Tarix'), 'currency' => __('Valyuta'), 'amount' => __('Məbləğ'), 'bank_account_id' => __('Bank hesabı'), 'applied_rate' => __('Bankın kursu')]);

        $account = BankAccount::findOrFail($data['bank_account_id']);
        if ($account->currency !== $data['currency']) {
            throw ValidationException::withMessages(['bank_account_id' => __('Seçilən hesab :v1 hesabıdır, ödəniş isə :v2 ilədir. Eyni valyutalı hesab seçin.', ['v1' => $account->currency, 'v2' => $data['currency']])]);
        }

        try {
            $this->ledger->record($account, [
                'direction' => $out ? 'out' : 'in',
                'transaction_date' => $data['transaction_date'],
                'amount' => $data['amount'],
                'counterparty_id' => $partyId,
                'contract_id' => $out ? $deal->purchase_contract_id : $deal->sale_contract_id,
                'project_id' => $deal->project_id,
                'deal_id' => $deal->id,
                'purpose' => ($data['purpose'] ?? null) ?: 'Trade '.$deal->code.($out ? __(' üzrə satıcıya ödəniş') : __(' üzrə alıcının ödənişi')),
                'reference' => $data['reference'] ?? null,
            ], $data['applied_rate'] ?? null);
        } catch (RateUnavailable $e) {
            throw ValidationException::withMessages(['transaction_date' => $e->getMessage().__(' Mədaxil yadda saxlanmadı.')]);
        }

        return redirect()->route('deals.show', [$deal, 'tab' => 'income'])->with('success', ($out ? __('Satıcıya ödəniş') : __('Mədaxil')).__(' qeydə alındı: ').money($data['amount'], $data['currency']).'.');
    }

    /** We pay the seller: invoice currency, bank's rate when the account differs, bank fee as an expense. */
    private function paySupplier(Request $request, Deal $deal): RedirectResponse
    {
        $request->merge([
            'amount' => parse_number($request->input('amount')),
            'bank_rate' => parse_number($request->input('bank_rate')),
            'fee_amount' => parse_number($request->input('fee_amount')),
        ]);
        $data = $request->validate([
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'bank_account_id' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'bank_rate' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
            'fee_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'reference' => ['nullable', 'string', 'max:80'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ], [], ['payment_date' => __('Tarix'), 'currency' => __('Valyuta'), 'amount' => __('Məbləğ'), 'bank_account_id' => __('Bank hesabı'), 'bank_rate' => __('Bankın kursu'), 'fee_amount' => __('Bank komissiyası')]);

        $p = app(\App\Services\SupplierPaymentService::class)->pay($deal, $data);

        return redirect()->route('deals.show', [$deal, 'tab' => 'income'])->with('success', __('Satıcıya ödəniş: ').money($p->amount, $p->currency)
            .' · hesabdan silindi: '.money($p->totalDebit(), $p->account_currency).((float) $p->fee_account_amount ? ' (komissiya '.money($p->fee_account_amount, $p->account_currency).' daxil)' : '').'.');
    }

    public function destroySupplierPayment(Deal $deal, \App\Models\SupplierPayment $supplierPayment): RedirectResponse
    {
        $this->authorize('bank.delete');
        abort_unless($supplierPayment->deal_id === $deal->id, 404);
        app(\App\Services\SupplierPaymentService::class)->delete($supplierPayment);

        return redirect()->route('deals.show', [$deal, 'tab' => 'income'])->with('success', __('Satıcıya ödəniş ləğv edildi; hesabdan silinmə və komissiya da silindi.'));
    }

    public function destroy(Deal $deal, BankTransaction $payment): RedirectResponse
    {
        $this->authorize('bank.delete');
        abort_unless($payment->deal_id === $deal->id, 404);
        $payment->delete();

        return redirect()->route('deals.show', [$deal, 'tab' => 'income'])->with('success', __('Mədaxil silindi.'));
    }
}
