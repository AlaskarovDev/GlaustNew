<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Rules\Iban;
use App\Services\Cbar\CurrencyRates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BankAccountController extends Controller
{
    public function index(CurrencyRates $rates): View
    {
        $accounts = BankAccount::withBalance()->withCount('transactions')->orderBy('is_active', 'desc')->orderBy('currency')->orderBy('name')->get();
        $today = $rates->today();
        $accounts->each(function (BankAccount $a) use ($rates, $today) {
            $rate = $rates->tryRate($a->currency, $today);
            $a->setAttribute('azn', $rate !== null ? round($a->currentBalance() * $rate, 2) : null);
        });

        return view('bank.accounts.index', compact('accounts'));
    }

    public function create(): View
    {
        $this->authorize('bank.create');

        return view('bank.accounts.form', ['account' => new BankAccount(['currency' => 'AZN', 'is_active' => true, 'opening_date' => today()])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('bank.create');
        BankAccount::create($this->validated($request));

        return redirect()->route('bank.accounts.index')->with('success', 'Bank hesabı əlavə edildi.');
    }

    public function edit(BankAccount $account): View
    {
        $this->authorize('bank.update');

        return view('bank.accounts.form', compact('account'));
    }

    public function update(Request $request, BankAccount $account): RedirectResponse
    {
        $this->authorize('bank.update');
        $data = $this->validated($request);
        if ($account->transactions()->exists() && $data['currency'] !== $account->currency) {
            return back()->withInput()->withErrors(['currency' => 'Əməliyyatları olan hesabın valyutası dəyişdirilə bilməz.']);
        }
        $account->update($data);

        return redirect()->route('bank.accounts.index')->with('success', 'Bank hesabı yeniləndi.');
    }

    public function destroy(BankAccount $account): RedirectResponse
    {
        $this->authorize('bank.delete');
        if ($account->transactions()->exists()) {
            $account->update(['is_active' => false]);

            return back()->with('info', 'Hesabda əməliyyatlar olduğu üçün silinmədi, deaktiv edildi.');
        }
        $account->delete();

        return back()->with('success', 'Bank hesabı silindi.');
    }

    private function validated(Request $request): array
    {
        $request->merge(['iban' => Iban::normalize($request->input('iban')), 'opening_balance' => parse_number($request->input('opening_balance')) ?? 0]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:120'],
            'iban' => ['nullable', new Iban],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
            'opening_balance' => ['required', 'numeric', 'min:-999999999999', 'max:999999999999'],
            'opening_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['bank_name' => 'Bank', 'opening_balance' => 'Başlanğıc qalıq']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
