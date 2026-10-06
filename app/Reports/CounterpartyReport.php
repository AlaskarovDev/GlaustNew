<?php

namespace App\Reports;

use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Tables\Column;
use Illuminate\Support\Collection;

/**
 * Without a counterparty: turnover per counterparty. With one: a reconciliation act
 * (akt-üzləşmə) — contracts as obligations and bank movements as settlements, with a running saldo in AZN.
 */
class CounterpartyReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'counterparties';
    }

    public static function title(): string
    {
        return __('Kontragent dövriyyəsi və akt-üzləşmə');
    }

    public static function description(): string
    {
        return __('Kontragentlər üzrə daxilolma/məxaric; bir kontragent seçəndə müqavilə öhdəlikləri və ödənişlərlə üzləşmə aktı.');
    }

    public static function icon(): string
    {
        return 'users';
    }

    public static function ability(): string
    {
        return 'crm.view';
    }

    public function filters(): array
    {
        return ['counterparty_id' => ['label' => __('Kontragent'), 'type' => 'select', 'options' => Counterparty::orderBy('name')->pluck('name', 'id')->all()]] + parent::filters();
    }

    private function selected(): ?Counterparty
    {
        return $this->request->filled('counterparty_id') ? Counterparty::find($this->request->integer('counterparty_id')) : null;
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        [$from, $to] = [$this->from()->toDateString(), $this->to()->toDateString()];

        if ($cp = $this->selected()) {
            $events = collect();
            foreach (Contract::where('counterparty_id', $cp->id)->whereNotIn('status', ['draft', 'cancelled'])->whereBetween('contract_date', [$from, $to.' 23:59:59'])->get() as $c) {
                // Sale: they owe us (debit). Purchase: we owe them (credit).
                $events->push(['date' => $c->contract_date, 'doc' => __('Müqavilə ').$c->number, 'text' => $c->subject,
                    'debit' => $c->kind === 'sale' ? (float) $c->amount_azn : null, 'credit' => $c->kind === 'purchase' ? (float) $c->amount_azn : null]);
            }
            foreach (BankTransaction::where('counterparty_id', $cp->id)->where('kind', 'regular')->whereBetween('transaction_date', [$from, $to.' 23:59:59'])->get() as $t) {
                // Money in from them reduces what they owe (credit); money out to them reduces what we owe (debit).
                $events->push(['date' => $t->transaction_date, 'doc' => ($t->direction === 'in' ? __('Daxilolma') : __('Ödəniş')).($t->reference ? ' № '.$t->reference : ''), 'text' => $t->purpose,
                    'debit' => $t->direction === 'out' ? (float) $t->amount_azn : null, 'credit' => $t->direction === 'in' ? (float) $t->amount_azn : null]);
            }
            $saldo = 0.0;

            return $this->data = $events->sortBy(fn ($e) => $e['date']->format('Ymd'))->values()->map(function ($e) use (&$saldo) {
                $saldo = round($saldo + ($e['debit'] ?? 0) - ($e['credit'] ?? 0), 2);

                return $e + ['saldo' => $saldo];
            });
        }

        $rows = BankTransaction::with('counterparty:id,name,voen,type')->where('kind', 'regular')->whereNotNull('counterparty_id')
            ->whereBetween('transaction_date', [$from, $to.' 23:59:59'])
            ->selectRaw('counterparty_id, direction, SUM(amount_azn) as s, COUNT(*) as n')->groupBy('counterparty_id', 'direction')->get()
            ->groupBy('counterparty_id');

        return $this->data = $rows->map(function ($g) {
            $cp = $g->first()->counterparty;
            $in = (float) $g->where('direction', 'in')->sum('s');
            $out = (float) $g->where('direction', 'out')->sum('s');

            return ['name' => $cp?->name, 'voen' => $cp?->voen, 'type' => $cp?->typeLabel(), 'in' => $in, 'out' => $out, 'total' => $in + $out, 'count' => (int) $g->sum('n')];
        })->sortByDesc('total')->values();
    }

    public function columns(): array
    {
        if ($this->selected()) {
            return [
                Column::make(__('Tarix'), 'date', 'date'),
                Column::make(__('Sənəd'), 'doc'),
                Column::make(__('Məzmun'), 'text', width: 36),
                Column::make(__('Debet (AZN)'), 'debit', 'money', total: true),
                Column::make(__('Kredit (AZN)'), 'credit', 'money', total: true),
                Column::make('Saldo (AZN)', 'saldo', 'money'),
            ];
        }

        return [
            Column::make(__('Kontragent'), 'name', width: 34),
            Column::make(__('VÖEN'), 'voen'),
            Column::make(__('Növ'), 'type'),
            Column::make(__('Daxilolma (AZN)'), 'in', 'money', total: true),
            Column::make(__('Məxaric (AZN)'), 'out', 'money', total: true),
            Column::make(__('Dövriyyə (AZN)'), 'total', 'money', total: true),
            Column::make(__('Əməliyyat sayı'), 'count', 'number'),
        ];
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $d = $this->data();
        if ($cp = $this->selected()) {
            $saldo = (float) ($d->last()['saldo'] ?? 0);

            return [
                ['label' => __('Debet'), 'value' => $d->sum('debit'), 'money' => true],
                ['label' => __('Kredit'), 'value' => $d->sum('credit'), 'money' => true],
                ['label' => $saldo >= 0 ? __('Kontragentin borcu') : __('Bizim borcumuz'), 'value' => abs($saldo), 'money' => true, 'tone' => $saldo >= 0 ? 'success' : 'danger'],
            ];
        }

        return [
            ['label' => __('Kontragent sayı'), 'value' => $d->count()],
            ['label' => __('Daxilolma'), 'value' => $d->sum('in'), 'money' => true, 'tone' => 'success'],
            ['label' => __('Məxaric'), 'value' => $d->sum('out'), 'money' => true, 'tone' => 'danger'],
        ];
    }

    public function chart(): ?array
    {
        if ($this->selected() || $this->data()->isEmpty()) {
            return null;
        }
        $top = $this->data()->take(10);

        return ['type' => 'bar', 'horizontal' => true, 'height' => 340, 'money' => true, 'stacked' => true, 'colors' => ['#0f9d8a', '#e9a23b'],
            'categories' => $top->pluck('name')->all(),
            'series' => [['name' => __('Daxilolma'), 'data' => $top->pluck('in')->all()], ['name' => __('Məxaric'), 'data' => $top->pluck('out')->all()]],
            'yaxis' => ['labels' => ['maxWidth' => 200]]];
    }

    public function note(): ?string
    {
        return $this->selected()
            ? __('Üzləşmə aktı: ').$this->selected()->name.__('. Debet — kontragentin bizə borcunu artırır (satış müqaviləsi, ona ödənişimiz); kredit — azaldır (ondan daxilolma, alış müqaviləsi). Məbləğlər CBAR məzənnəsi ilə AZN-dədir.')
            : __('Akt-üzləşmə üçün filtrdən kontragent seçin.');
    }
}
