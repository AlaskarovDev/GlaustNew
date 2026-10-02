<?php

namespace App\Reports;

use App\Models\BankTransaction;
use App\Tables\Column;
use Illuminate\Support\Collection;

/**
 * Exchange differences: regular movements booked at a bank rate other than CBAR,
 * and conversions (AZN value received minus AZN value given, both at CBAR).
 */
class ExchangeReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'exchange';
    }

    public static function title(): string
    {
        return 'Kurs fərqi';
    }

    public static function description(): string
    {
        return 'Bankın faktiki məzənnəsi ilə CBAR arasındakı fərq və konvertasiyaların nəticəsi, AZN.';
    }

    public static function icon(): string
    {
        return 'coins';
    }

    public static function ability(): string
    {
        return 'bank.view';
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        [$from, $to] = [$this->from()->toDateString(), $this->to()->toDateString()];

        $regular = BankTransaction::with('account:id,name', 'counterparty:id,name')->where('kind', 'regular')->where('currency', '!=', 'AZN')
            ->whereColumn('applied_rate', '!=', 'cbar_rate')->whereBetween('transaction_date', [$from, $to])->get()
            ->map(fn ($t) => [
                'date' => $t->transaction_date, 'type' => $t->direction === 'in' ? 'Mədaxil' : 'Məxaric', 'account' => $t->account?->name,
                'detail' => $t->counterparty?->name ?? $t->purpose, 'amount' => (float) $t->amount, 'currency' => $t->currency,
                'cbar' => (float) $t->cbar_rate, 'applied' => (float) $t->applied_rate, 'diff' => $t->exchangeDifference(),
            ]);

        $conversions = BankTransaction::with('account:id,name')->where('kind', 'conversion')->whereBetween('transaction_date', [$from, $to])
            ->get()->groupBy('transfer_group')
            ->map(function ($legs) {
                $out = $legs->firstWhere('direction', 'out');
                $in = $legs->firstWhere('direction', 'in');
                if (! $out || ! $in) {
                    return null;
                }

                return [
                    'date' => $out->transaction_date, 'type' => 'Konvertasiya', 'account' => $out->account?->name.' → '.$in->account?->name,
                    'detail' => num($out->amount).' '.$out->currency.' → '.num($in->amount).' '.$in->currency,
                    'amount' => (float) $out->amount, 'currency' => $out->currency, 'cbar' => (float) $out->cbar_rate,
                    'applied' => $out->amount > 0 ? round($in->cbar_amount_azn / $out->amount, 8) : null,
                    'diff' => round((float) $in->cbar_amount_azn - (float) $out->cbar_amount_azn, 2),
                ];
            })->filter()->values();

        return $this->data = $regular->concat($conversions)->sortBy(fn ($r) => $r['date']->format('Ymd'))->values();
    }

    public function columns(): array
    {
        return [
            Column::make('Tarix', 'date', 'date'),
            Column::make('Növ', 'type'),
            Column::make('Hesab', 'account'),
            Column::make('Təfərrüat', 'detail', width: 34),
            Column::make('Məbləğ', 'amount', 'money'),
            Column::make('Valyuta', 'currency'),
            Column::make('CBAR', 'cbar', 'rate'),
            Column::make('Faktiki', 'applied', 'rate'),
            Column::make('Kurs fərqi (AZN)', 'diff', 'money', total: true),
        ];
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $d = $this->data();
        $gain = $d->where('diff', '>', 0)->sum('diff');
        $loss = $d->where('diff', '<', 0)->sum('diff');

        return [
            ['label' => 'Müsbət fərq', 'value' => $gain, 'money' => true, 'tone' => 'success'],
            ['label' => 'Mənfi fərq', 'value' => abs($loss), 'money' => true, 'tone' => 'danger'],
            ['label' => 'Xalis nəticə', 'value' => $gain + $loss, 'money' => true, 'tone' => $gain + $loss >= 0 ? 'success' : 'danger'],
        ];
    }

    public function note(): ?string
    {
        return 'Müsbət dəyər şirkət üçün CBAR-dan sərfəli kursu göstərir. Konvertasiyada hər iki tərəf öz tarixinin CBAR məzənnəsi ilə qiymətləndirilir.';
    }
}
