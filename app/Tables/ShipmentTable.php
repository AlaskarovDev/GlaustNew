<?php

namespace App\Tables;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

class ShipmentTable extends Table
{
    protected array $searchable = ['number', 'origin', 'destination', 'container_no', 'document_no', 'vehicle', 'cargo_description', 'carrier.name'];

    protected array $sortable = ['created' => 'created_at', 'eta' => 'eta', 'loading' => 'loading_date', 'number' => 'number'];

    public function title(): string
    {
        return __('Yüklər');
    }

    protected function baseQuery(): Builder
    {
        return Shipment::query()->with(['carrier:id,name', 'project:id,code', 'contract:id,number', 'responsible:id,name,email'])->withSum('costs', 'amount_azn');
    }

    public function filters(): array
    {
        return [
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => status_options('shipment') + ['open' => __('Açıq (təhvil verilməmiş)'), 'delayed' => __('Gecikən')]],
            'direction' => ['label' => __('İstiqamət'), 'type' => 'select', 'options' => config('glaust.shipment_directions')],
            'transport_mode' => ['label' => __('Nəqliyyat'), 'type' => 'select', 'options' => config('glaust.transport_modes')],
            'from' => ['label' => __('Yüklənmə tarixindən'), 'type' => 'date'],
            'to' => ['label' => __('Tarixədək'), 'type' => 'date'],
        ];
    }

    protected function applyFilters(Builder $query): void
    {
        $r = $this->request;
        match ($r->query('status')) {
            null, '' => null,
            'open' => $query->where('status', '!=', 'delivered'),
            'delayed' => $query->whereNotIn('status', ['arrived', 'delivered'])->where('eta', '<', today()->toDateString()),
            'in_transit' => $query->whereIn('status', ['loading', 'in_transit', 'customs']),
            default => $query->where('status', $r->query('status')),
        };
        foreach (['direction', 'transport_mode'] as $f) {
            if ($r->filled($f)) {
                $query->where($f, $r->query($f));
            }
        }
        foreach (['carrier_id', 'project_id', 'contract_id'] as $f) {
            if ($r->filled($f)) {
                $query->where($f, $r->integer($f));
            }
        }
        if ($r->filled('from')) {
            $query->where('loading_date', '>=', $r->date('from')->toDateString());
        }
        if ($r->filled('to')) {
            $query->where('loading_date', '<=', $r->date('to')->toDateString());
        }
    }

    public function columns(): array
    {
        return [
            Column::make(__('Nömrə'), 'number'),
            Column::make(__('İstiqamət'), fn ($s) => config('glaust.shipment_directions.'.$s->direction)),
            Column::make(__('Çıxış'), 'origin'),
            Column::make(__('Təyinat'), 'destination'),
            Column::make(__('Daşıyıcı'), 'carrier.name'),
            Column::make(__('Nəqliyyat'), fn ($s) => config('glaust.transport_modes.'.$s->transport_mode)),
            Column::make(__('Nəqliyyat vasitəsi'), 'vehicle'),
            Column::make(__('Konteyner'), 'container_no'),
            Column::make(__('CMR / konosament'), 'document_no'),
            Column::make(__('Yük'), 'cargo_description', width: 30),
            Column::make(__('Çəki (kq)'), 'weight_kg', 'number'),
            Column::make(__('Həcm (m³)'), 'volume_m3', 'number'),
            Column::make(__('Yüklənmə'), 'loading_date', 'date'),
            Column::make(__('Gözlənilən çatma'), 'eta', 'date'),
            Column::make('Status', fn ($s) => status_label('shipment', $s->status)),
            Column::make(__('Xərclər (AZN)'), fn ($s) => (float) $s->costs_sum_amount_azn, 'money', total: true),
            Column::make(__('Layihə'), 'project.code'),
            Column::make(__('Müqavilə'), 'contract.number'),
        ];
    }
}
