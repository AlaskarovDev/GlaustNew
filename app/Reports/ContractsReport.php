<?php

namespace App\Reports;

use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Tables\Column;
use Illuminate\Support\Collection;

class ContractsReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'contracts';
    }

    public static function title(): string
    {
        return __('Müqavilələrin icrası');
    }

    public static function description(): string
    {
        return __('Hər müqavilə üzrə məbləğ, bank vasitəsilə icra, qalıq və ödəniş qrafikinin vəziyyəti.');
    }

    public static function icon(): string
    {
        return 'signature';
    }

    public static function ability(): string
    {
        return 'contracts.view';
    }

    public function filters(): array
    {
        return parent::filters() + [
            'kind' => ['label' => __('Növ'), 'type' => 'select', 'options' => ['sale' => __('Satış'), 'purchase' => __('Alış')]],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => status_options('contract')],
        ];
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        $contracts = Contract::with('counterparty:id,name')
            ->whereBetween('contract_date', [$this->from()->toDateString(), $this->to()->toDateString()])
            ->when(in_array($this->request->query('kind'), ['sale', 'purchase'], true), fn ($q) => $q->where('kind', $this->request->query('kind')))
            ->when($this->request->filled('status'), fn ($q) => $q->where('status', $this->request->query('status')))
            ->orderBy('contract_date')->get();

        $ids = $contracts->pluck('id');
        $settled = BankTransaction::whereIn('contract_id', $ids)->where('kind', 'regular')
            ->selectRaw('contract_id, direction, SUM(amount_azn) as s')->groupBy('contract_id', 'direction')->get()->groupBy('contract_id');
        $payments = ContractPayment::whereIn('contract_id', $ids)->get()->groupBy('contract_id');

        return $this->data = $contracts->map(function (Contract $c) use ($settled, $payments) {
            $dir = $c->kind === 'sale' ? 'in' : 'out';
            $done = (float) ($settled[$c->id] ?? collect())->where('direction', $dir)->sum('s');
            $plan = $payments[$c->id] ?? collect();
            $overdue = $plan->whereNull('paid_at')->filter(fn ($p) => $p->due_date->lt(today()));

            return [
                'number' => $c->number, 'date' => $c->contract_date, 'party' => $c->counterparty?->name, 'kind' => $c->kind === 'sale' ? __('Satış') : __('Alış'),
                'status' => status_label('contract', $c->status), 'amount_azn' => (float) $c->amount_azn, 'settled' => $done,
                'left' => round(max(0, (float) $c->amount_azn - $done), 2),
                'pct' => $c->amount_azn > 0 ? round($done / (float) $c->amount_azn * 100, 1) : 0,
                'overdue' => (float) $overdue->sum('amount'), 'overdue_n' => $overdue->count(), 'end' => $c->end_date,
            ];
        });
    }

    public function columns(): array
    {
        return [
            Column::make(__('Nömrə'), 'number'),
            Column::make(__('Tarix'), 'date', 'date'),
            Column::make(__('Kontragent'), 'party', width: 30),
            Column::make(__('Növ'), 'kind'),
            Column::make('Status', 'status'),
            Column::make(__('Məbləğ (AZN)'), 'amount_azn', 'money', total: true),
            Column::make(__('İcra (AZN)'), 'settled', 'money', total: true),
            Column::make(__('Qalıq (AZN)'), 'left', 'money', total: true),
            Column::make(__('İcra %'), 'pct', 'number'),
            Column::make(__('Gecikmiş ödəniş'), 'overdue', 'money', total: true),
            Column::make(__('Bitmə'), 'end', 'date'),
        ];
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $d = $this->data();

        return [
            ['label' => __('Müqavilələr'), 'value' => $d->count()],
            ['label' => __('Ümumi məbləğ'), 'value' => $d->sum('amount_azn'), 'money' => true],
            ['label' => __('İcra olunub'), 'value' => $d->sum('settled'), 'money' => true, 'tone' => 'success'],
            ['label' => __('Gecikmiş ödənişlər'), 'value' => $d->sum('overdue'), 'money' => true, 'tone' => $d->sum('overdue') > 0 ? 'danger' : null],
        ];
    }

    public function chart(): ?array
    {
        $d = $this->data()->sortByDesc('amount_azn')->take(12);
        if ($d->isEmpty()) {
            return null;
        }

        return ['type' => 'bar', 'height' => 300, 'money' => true, 'stacked' => true, 'colors' => ['#0f9d8a', '#cbd5e1'],
            'categories' => $d->pluck('number')->all(),
            'series' => [['name' => __('İcra'), 'data' => $d->pluck('settled')->all()], ['name' => __('Qalıq'), 'data' => $d->pluck('left')->all()]]];
    }
}
