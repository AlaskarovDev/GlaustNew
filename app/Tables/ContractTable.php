<?php

namespace App\Tables;

use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

class ContractTable extends Table
{
    protected array $searchable = ['number', 'subject', 'counterparty.name', 'counterparty.voen'];

    protected array $sortable = ['date' => 'contract_date', 'end' => 'end_date', 'amount' => 'amount_azn', 'number' => 'number'];

    public function title(): string
    {
        return __('Müqavilələr');
    }

    protected function baseQuery(): Builder
    {
        return Contract::query()->with(['counterparty:id,name,voen,type', 'responsible:id,name', 'project:id,code,name']);
    }

    public function filters(): array
    {
        return [
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => status_options('contract') + ['open' => __('Açıq (imzalanmış + icrada)')]],
            'kind' => ['label' => __('Növ'), 'type' => 'select', 'options' => ['sale' => __('Satış'), 'purchase' => __('Alış')]],
            'currency' => ['label' => __('Valyuta'), 'type' => 'select', 'options' => array_combine(config('glaust.currencies'), config('glaust.currencies'))],
            'ending' => ['label' => __('Bitir'), 'type' => 'select', 'options' => ['7' => __('7 gün ərzində'), '30' => __('30 gün ərzində'), '90' => __('90 gün ərzində'), 'expired' => __('Müddəti keçib')]],
            'from' => ['label' => __('Tarixdən'), 'type' => 'date'],
            'to' => ['label' => __('Tarixədək'), 'type' => 'date'],
        ];
    }

    protected function applyFilters(Builder $query): void
    {
        $r = $this->request;
        if ($r->query('status') === 'open' || $r->query('status') === 'active') {
            $r->query('status') === 'open'
                ? $query->whereIn('status', ['signed', 'active'])
                : $query->where('status', 'active');
        } elseif (array_key_exists((string) $r->query('status'), config('glaust.statuses.contract'))) {
            $query->where('status', $r->query('status'));
        }
        if (in_array($r->query('kind'), ['sale', 'purchase'], true)) {
            $query->where('kind', $r->query('kind'));
        }
        if (in_array($r->query('currency'), config('glaust.currencies'), true)) {
            $query->where('currency', $r->query('currency'));
        }
        if ($ending = $r->query('ending')) {
            $ending === 'expired'
                ? $query->whereIn('status', ['signed', 'active'])->where('end_date', '<', today()->toDateString())
                : $query->whereIn('status', ['signed', 'active'])->whereBetween('end_date', [today()->toDateString(), today()->addDays((int) $ending)->toDateString().' 23:59:59']);
        }
        if ($r->filled('counterparty_id')) {
            $query->where('counterparty_id', $r->integer('counterparty_id'));
        }
        if ($r->filled('from')) {
            $query->where('contract_date', '>=', $r->date('from')->toDateString());
        }
        if ($r->filled('to')) {
            $query->where('contract_date', '<=', $r->date('to')->toDateString());
        }
    }

    public function columns(): array
    {
        return [
            Column::make(__('Nömrə'), 'number'),
            Column::make(__('Tarix'), 'contract_date', 'date'),
            Column::make(__('Kontragent'), 'counterparty.name', width: 30),
            Column::make(__('VÖEN'), 'counterparty.voen'),
            Column::make(__('Növ'), fn ($c) => $c->kind === 'sale' ? __('Satış') : __('Alış')),
            Column::make(__('Mövzu'), 'subject', width: 36),
            Column::make(__('Məbləğ'), 'amount', 'money'),
            Column::make(__('Valyuta'), 'currency'),
            Column::make(__('CBAR məzənnəsi'), 'cbar_rate', 'rate'),
            Column::make(__('AZN ekvivalenti'), 'amount_azn', 'money', total: true),
            Column::make(__('Başlama'), 'start_date', 'date'),
            Column::make(__('Bitmə'), 'end_date', 'date'),
            Column::make('Status', fn ($c) => status_label('contract', $c->status)),
            Column::make(__('Layihə'), 'project.code'),
            Column::make(__('Məsul'), 'responsible.name'),
        ];
    }
}
