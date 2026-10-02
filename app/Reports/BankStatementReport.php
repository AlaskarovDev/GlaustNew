<?php

namespace App\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Tables\Column;
use Illuminate\Support\Collection;

/** Movements of one account with opening balance, running balance and closing balance. */
class BankStatementReport extends Report
{
    private ?array $built = null;

    public static function key(): string
    {
        return 'bank-statement';
    }

    public static function title(): string
    {
        return 'Bank hesabı üzrə hərəkət';
    }

    public static function description(): string
    {
        return 'Seçilmiş hesab üzrə dövrün əvvəlinə qalıq, bütün hərəkətlər, cari qalıq və dövrün sonuna qalıq.';
    }

    public static function icon(): string
    {
        return 'bank';
    }

    public static function ability(): string
    {
        return 'bank.view';
    }

    public function filters(): array
    {
        return ['account_id' => ['label' => 'Hesab', 'type' => 'select', 'options' => BankAccount::orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.$a->currency.')'])->all()]] + parent::filters();
    }

    private function account(): ?BankAccount
    {
        return BankAccount::find($this->request->integer('account_id')) ?? BankAccount::orderBy('id')->first();
    }

    private function build(): array
    {
        if ($this->built) {
            return $this->built;
        }
        $account = $this->account();
        if (! $account) {
            return $this->built = ['account' => null, 'opening' => 0, 'rows' => collect(), 'in' => 0, 'out' => 0];
        }
        $signed = "SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END)";
        $before = (float) BankTransaction::where('bank_account_id', $account->id)->where('transaction_date', '<', $this->from()->toDateString())->selectRaw("COALESCE({$signed}, 0) as s")->value('s');
        $opening = round((float) $account->opening_balance + $before, 2);

        $running = $opening;
        $rows = BankTransaction::with('counterparty:id,name', 'contract:id,number')
            ->where('bank_account_id', $account->id)
            ->whereBetween('transaction_date', [$this->from()->toDateString(), $this->to()->toDateString()])
            ->orderBy('transaction_date')->orderBy('id')->get()
            ->map(function ($t) use (&$running) {
                $running = round($running + ($t->direction === 'in' ? 1 : -1) * (float) $t->amount, 2);

                return [
                    'date' => $t->transaction_date, 'party' => $t->counterparty?->name ?? config('glaust.transaction_kinds.'.$t->kind),
                    'purpose' => trim(($t->contract ? $t->contract->number.' · ' : '').($t->purpose ?? '')),
                    'in' => $t->direction === 'in' ? (float) $t->amount : null, 'out' => $t->direction === 'out' ? (float) $t->amount : null,
                    'balance' => $running, 'rate' => (float) $t->cbar_rate, 'reference' => $t->reference,
                ];
            });

        return $this->built = ['account' => $account, 'opening' => $opening, 'rows' => $rows, 'in' => $rows->sum('in'), 'out' => $rows->sum('out')];
    }

    public function columns(): array
    {
        $cur = $this->build()['account']?->currency ?? '';

        return [
            Column::make('Tarix', 'date', 'date'),
            Column::make('Kontragent', 'party', width: 28),
            Column::make('Təyinat', 'purpose', width: 36),
            Column::make('Sənəd №', 'reference'),
            Column::make("Mədaxil ($cur)", 'in', 'money', total: true),
            Column::make("Məxaric ($cur)", 'out', 'money', total: true),
            Column::make("Qalıq ($cur)", 'balance', 'money'),
            Column::make('CBAR', 'rate', 'rate'),
        ];
    }

    public function rows(): iterable
    {
        return $this->build()['rows'];
    }

    public function summary(): array
    {
        $b = $this->build();
        $cur = $b['account']?->currency ?? 'AZN';

        return [
            ['label' => 'Dövrün əvvəlinə qalıq', 'value' => $b['opening'], 'money' => true, 'currency' => $cur],
            ['label' => 'Mədaxil', 'value' => $b['in'], 'money' => true, 'currency' => $cur, 'tone' => 'success'],
            ['label' => 'Məxaric', 'value' => $b['out'], 'money' => true, 'currency' => $cur, 'tone' => 'danger'],
            ['label' => 'Dövrün sonuna qalıq', 'value' => round($b['opening'] + $b['in'] - $b['out'], 2), 'money' => true, 'currency' => $cur],
        ];
    }

    public function chart(): ?array
    {
        $rows = $this->build()['rows'];
        if ($rows->count() < 2) {
            return null;
        }
        $daily = $rows->groupBy(fn ($r) => $r['date']->format('d.m'))->map(fn (Collection $g) => $g->last()['balance']);

        return ['type' => 'area', 'height' => 260, 'decimals' => 2, 'colors' => ['#6366f1'], 'categories' => $daily->keys()->all(),
            'series' => [['name' => 'Qalıq', 'data' => $daily->values()->all()]]];
    }

    public function note(): ?string
    {
        return $this->build()['account'] ? 'Hesab: '.$this->build()['account']->name.' · '.$this->build()['account']->bank_name : 'Bank hesabı yoxdur.';
    }
}
