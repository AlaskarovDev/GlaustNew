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

    /**
     * Hesab çıxarışı: every movement of one account in a period, with the balance before it,
     * money in / out, the running balance after each line and the closing balance.
     */
    public function statement(Request $request, BankAccount $account): View|\Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'format' => ['nullable', 'in:xlsx,pdf']]);
        $from = isset($data['from']) ? \Carbon\CarbonImmutable::parse($data['from']) : null;
        $to = isset($data['to']) ? \Carbon\CarbonImmutable::parse($data['to']) : null;

        $signed = "COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)";
        $before = $from ? (float) $account->transactions()->where('transaction_date', '<', $from->toDateString())->selectRaw($signed.' as s')->value('s') : 0.0;
        $opening = round((float) $account->opening_balance + $before, 2);

        $rows = $account->transactions()
            ->with(['counterparty:id,name', 'deal:id,code', 'project:id,code', 'category:id,name'])
            ->when($from, fn ($q) => $q->where('transaction_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('transaction_date', '<=', $to->toDateString()))
            ->orderBy('transaction_date')->orderBy('id')->get();
        $running = $opening;
        foreach ($rows as $tx) {
            $running = round($running + ($tx->direction === 'in' ? 1 : -1) * (float) $tx->amount, 2);
            $tx->setAttribute('running_balance', $running);
        }
        $in = round($rows->where('direction', 'in')->sum('amount'), 2);
        $out = round($rows->where('direction', 'out')->sum('amount'), 2);
        $period = ($from ? azdate($from) : __('əvvəldən')).' — '.($to ? azdate($to) : __('bu günə'));

        if ($format = $data['format'] ?? null) {
            $cur = $account->currency;
            $cols = [
                \App\Tables\Column::make(__('Tarix'), 'transaction_date', 'date'),
                \App\Tables\Column::make(__('Qarşı tərəf'), fn ($t) => $t->counterparty?->name ?? ($t->kind !== 'regular' ? __('Daxili köçürmə') : '—')),
                \App\Tables\Column::make(__('Təyinat'), fn ($t) => trim(($t->purpose ?? '').($t->deal ? ' · '.$t->deal->code : ''))),
                \App\Tables\Column::make(__('İstinad'), 'reference'),
                \App\Tables\Column::make(__('Mədaxil (').$cur.')', fn ($t) => $t->direction === 'in' ? (float) $t->amount : null, 'money', total: true),
                \App\Tables\Column::make(__('Məxaric (').$cur.')', fn ($t) => $t->direction === 'out' ? (float) $t->amount : null, 'money', total: true),
                \App\Tables\Column::make(__('Qalıq (').$cur.')', 'running_balance', 'money'),
                \App\Tables\Column::make(__('Kurs'), 'applied_rate', 'rate'),
                \App\Tables\Column::make('AZN', 'amount_azn', 'money'),
            ];
            $title = __('Hesab çıxarışı — ').$account->name.' ('.$cur.')';
            $filters = [$account->bank_name.($account->iban ? ' · '.$account->iban : ''), __('Dövr: ').$period, __('Əvvəlki qalıq: ').money($opening, $cur).__(' · Son qalıq: ').money($running, $cur)];
            $name = 'cixaris-'.\Illuminate\Support\Str::slug($account->name.'-'.$cur).'-'.now()->format('Y-m-d');

            return $format === 'pdf'
                ? app(\App\Support\Export\PdfExporter::class)->download($title, $cols, $rows, $name.'.pdf', $filters)
                : app(\App\Support\Export\SpreadsheetExporter::class)->download($title, $cols, $rows, $name.'.xlsx', $filters);
        }

        return view('bank.accounts.statement', [
            'account' => $account, 'rows' => $rows, 'opening' => $opening, 'closing' => $running,
            'in' => $in, 'out' => $out, 'from' => $from, 'to' => $to, 'period' => $period,
        ]);
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

        return redirect()->route('bank.accounts.index')->with('success', __('Bank hesabı əlavə edildi.'));
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
            return back()->withInput()->withErrors(['currency' => __('Əməliyyatları olan hesabın valyutası dəyişdirilə bilməz.')]);
        }
        $account->update($data);

        return redirect()->route('bank.accounts.index')->with('success', __('Bank hesabı yeniləndi.'));
    }

    public function destroy(BankAccount $account): RedirectResponse
    {
        $this->authorize('bank.delete');
        if ($account->transactions()->exists()) {
            $account->update(['is_active' => false]);

            return back()->with('info', __('Hesabda əməliyyatlar olduğu üçün silinmədi, deaktiv edildi.'));
        }
        $account->delete();

        return back()->with('success', __('Bank hesabı silindi.'));
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
        ], [], ['bank_name' => 'Bank', 'opening_balance' => __('Başlanğıc qalıq')]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
