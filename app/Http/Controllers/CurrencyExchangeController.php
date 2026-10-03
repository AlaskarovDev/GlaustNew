<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CurrencyExchange;
use App\Rules\TenantExists;
use App\Services\CurrencyExchangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Valyuta alış-satışı: buy or sell a currency through our bank accounts, CBAR vs bank rate kept. */
class CurrencyExchangeController extends Controller
{
    public function __construct(private CurrencyExchangeService $exchanges) {}

    public function index(): View
    {
        $list = CurrencyExchange::with(['fromAccount', 'toAccount', 'creator'])->orderByDesc('exchange_date')->orderByDesc('id')->paginate(25);
        $totals = CurrencyExchange::selectRaw('COUNT(*) as n, COALESCE(SUM(difference_azn), 0) as diff_azn')->first();

        return view('bank.exchanges.index', [
            'list' => $list,
            'totals' => $totals,
            'accounts' => BankAccount::where('is_active', true)->withBalance()->orderBy('bank_name')->orderBy('currency')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('bank.create');
        $request->merge(['amount' => parse_number($request->input('amount')), 'bank_rate' => parse_number($request->input('bank_rate'))]);
        $currencies = Rule::in(config('glaust.currencies'));
        $data = $request->validate([
            'exchange_date' => ['required', 'date', 'before_or_equal:today'],
            'direction' => ['required', Rule::in(array_keys(CurrencyExchange::DIRECTIONS))],
            'currency' => ['required', $currencies],
            'counter_currency' => ['required', $currencies, 'different:currency'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'bank_rate' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'from_account_id' => ['required', 'integer', TenantExists::in('bank_accounts')],
            'to_account_id' => ['required', 'integer', TenantExists::in('bank_accounts'), 'different:from_account_id'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['counter_currency.different' => 'Fərqli valyuta seçin.', 'bank_rate.required' => 'Bankın kursunu daxil edin.'], [
            'exchange_date' => 'Tarix', 'currency' => 'Valyuta', 'counter_currency' => 'Qarşı valyuta', 'amount' => 'Məbləğ', 'bank_rate' => 'Bankın kursu',
            'from_account_id' => 'Silinən hesab', 'to_account_id' => 'Mədaxil hesabı',
        ]);
        $x = $this->exchanges->execute($data);

        return redirect()->route('bank.exchanges.index')->with('success', CurrencyExchange::DIRECTIONS[$x->direction].' icra edildi: '.money($x->amount, $x->currency)
            .' ↔ '.money($x->counter_amount, $x->counter_currency).'. CBAR ilə fərq: '.money($x->difference, $x->counter_currency).'.');
    }

    public function destroy(CurrencyExchange $exchange): RedirectResponse
    {
        $this->authorize('bank.delete');
        $this->exchanges->delete($exchange);

        return back()->with('success', 'Əməliyyat ləğv edildi; bank hesablarındakı hərəkətlər silindi.');
    }
}
