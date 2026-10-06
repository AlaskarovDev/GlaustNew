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
        return __('Kurs fərqi');
    }

    public static function description(): string
    {
        return __('Bankın faktiki məzənnəsi ilə CBAR arasındakı fərq və konvertasiyaların nəticəsi, AZN.');
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
            ->whereColumn('applied_rate', '!=', 'cbar_rate')->whereBetween('transaction_date', [$from, $to.' 23:59:59'])->get()
            ->map(fn ($t) => [
                'date' => $t->transaction_date, 'type' => $t->direction === 'in' ? __('Mədaxil') : __('Məxaric'), 'account' => $t->account?->name,
                'detail' => $t->counterparty?->name ?? $t->purpose, 'amount' => (float) $t->amount, 'currency' => $t->currency,
                'cbar' => (float) $t->cbar_rate, 'applied' => (float) $t->applied_rate, 'diff' => $t->exchangeDifference(),
            ]);

        $conversionLegs = BankTransaction::with('account:id,name')->where('kind', 'conversion')->whereBetween('transaction_date', [$from, $to.' 23:59:59'])->get();
        // conversions made in «Valyuta alış-satışı»: named after the currency bought / sold, with their Trade
        $exchanges = \App\Models\CurrencyExchange::with('deal:id,code', 'project:id,code')->whereIn('out_transaction_id', $conversionLegs->pluck('id'))->get()->keyBy('out_transaction_id');
        $conversions = $conversionLegs->groupBy('transfer_group')
            ->map(function ($legs) use ($exchanges) {
                $out = $legs->firstWhere('direction', 'out');
                $in = $legs->firstWhere('direction', 'in');
                if (! $out || ! $in) {
                    return null;
                }
                if ($x = $exchanges->get($out->id)) {
                    return [
                        'date' => $x->exchange_date, 'type' => ($x->direction === 'buy' ? __('Valyuta alışı') : __('Valyuta satışı')).' ('.$x->currency.')',
                        'account' => $out->account?->name.' → '.$in->account?->name,
                        'detail' => num($x->amount).' '.$x->currency.' · '.__('bank kursu').' '.rate_fmt($x->bank_rate).' / CBAR '.rate_fmt($x->cbar_cross)
                            .($x->deal ? ' · Trade '.$x->deal->code : ($x->project ? ' · '.$x->project->code : '')),
                        'amount' => (float) $x->amount, 'currency' => $x->currency, 'cbar' => (float) $x->cbar_cross, 'applied' => (float) $x->bank_rate,
                        'diff' => -(float) $x->difference_azn,
                    ];
                }

                return [
                    'date' => $out->transaction_date, 'type' => __('Konvertasiya'), 'account' => $out->account?->name.' → '.$in->account?->name,
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
            Column::make(__('Tarix'), 'date', 'date'),
            Column::make(__('Növ'), 'type'),
            Column::make(__('Hesab'), 'account'),
            Column::make(__('Təfərrüat'), 'detail', width: 34),
            Column::make(__('Məbləğ'), 'amount', 'money'),
            Column::make(__('Valyuta'), 'currency'),
            Column::make('CBAR', 'cbar', 'rate'),
            Column::make(__('Faktiki'), 'applied', 'rate'),
            Column::make(__('Kurs fərqi (AZN)'), 'diff', 'money', total: true),
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

        $out = [
            ['label' => __('Müsbət fərq'), 'value' => $gain, 'money' => true, 'tone' => 'success'],
            ['label' => __('Mənfi fərq'), 'value' => abs($loss), 'money' => true, 'tone' => 'danger'],
            ['label' => __('Xalis nəticə'), 'value' => $gain + $loss, 'money' => true, 'tone' => $gain + $loss >= 0 ? 'success' : 'danger'],
        ];
        // per currency: "RUB məzənnə fərqi — xərc"
        foreach ($d->groupBy('currency')->sortKeys() as $cur => $g) {
            if (($l = abs($g->where('diff', '<', 0)->sum('diff'))) >= 0.005) {
                $out[] = ['label' => __(':cur məzənnə fərqi — xərc', ['cur' => $cur]), 'value' => $l, 'money' => true, 'tone' => 'danger'];
            }
            if (($p = $g->where('diff', '>', 0)->sum('diff')) >= 0.005) {
                $out[] = ['label' => __(':cur məzənnə fərqi — gəlir', ['cur' => $cur]), 'value' => $p, 'money' => true, 'tone' => 'success'];
            }
        }

        return $out;
    }

    public function note(): ?string
    {
        return __('Müsbət dəyər şirkət üçün CBAR-dan sərfəli kursu göstərir. Konvertasiyada hər iki tərəf öz tarixinin CBAR məzənnəsi ilə qiymətləndirilir.');
    }
}
