<?php

namespace App\Reports;

use App\Models\Shipment;
use App\Tables\Column;
use Illuminate\Support\Collection;

class LogisticsReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'logistics';
    }

    public static function title(): string
    {
        return 'Logistika xərcləri və gecikmələr';
    }

    public static function description(): string
    {
        return 'Dövrdə yüklənən yüklər: xərclər növlər üzrə (AZN), planlı və faktiki çatma, gecikmə günləri.';
    }

    public static function icon(): string
    {
        return 'truck';
    }

    public static function ability(): string
    {
        return 'logistics.view';
    }

    public function filters(): array
    {
        return parent::filters() + [
            'transport_mode' => ['label' => 'Nəqliyyat', 'type' => 'select', 'options' => config('glaust.transport_modes')],
            'direction' => ['label' => 'İstiqamət', 'type' => 'select', 'options' => config('glaust.shipment_directions')],
        ];
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        $types = array_keys(config('glaust.cost_types'));

        return $this->data = Shipment::with(['carrier:id,name', 'costs'])
            ->whereBetween('loading_date', [$this->from()->toDateString(), $this->to()->toDateString()])
            ->when($this->request->filled('transport_mode'), fn ($q) => $q->where('transport_mode', $this->request->query('transport_mode')))
            ->when($this->request->filled('direction'), fn ($q) => $q->where('direction', $this->request->query('direction')))
            ->orderBy('loading_date')->get()
            ->map(function (Shipment $s) use ($types) {
                $arrived = $s->delivered_at ?? ($s->status === 'arrived' ? $s->updated_at : null);
                $delay = null;
                if ($s->eta) {
                    $ref = $arrived ? $arrived->copy()->startOfDay() : (in_array($s->status, ['arrived', 'delivered'], true) ? null : today());
                    $delay = $ref ? max(0, (int) $s->eta->diffInDays($ref, false)) : null;
                }
                $row = [
                    'number' => $s->number, 'route' => $s->origin.' → '.$s->destination, 'carrier' => $s->carrier?->name,
                    'mode' => config('glaust.transport_modes.'.$s->transport_mode), 'loading' => $s->loading_date, 'eta' => $s->eta,
                    'arrived' => $arrived, 'delay' => $delay, 'status' => status_label('shipment', $s->status),
                    'total' => (float) $s->costs->sum('amount_azn'),
                ];
                foreach ($types as $t) {
                    $row['c_'.$t] = (float) $s->costs->where('cost_type', $t)->sum('amount_azn');
                }

                return $row;
            });
    }

    public function columns(): array
    {
        $cols = [
            Column::make('Nömrə', 'number'),
            Column::make('Marşrut', 'route', width: 30),
            Column::make('Daşıyıcı', 'carrier'),
            Column::make('Nəqliyyat', 'mode'),
            Column::make('Yüklənmə', 'loading', 'date'),
            Column::make('Plan çatma', 'eta', 'date'),
            Column::make('Faktiki', 'arrived', 'date'),
            Column::make('Gecikmə (gün)', 'delay', 'number'),
            Column::make('Status', 'status'),
        ];
        foreach (config('glaust.cost_types') as $k => $label) {
            $cols[] = Column::make($label.' (AZN)', 'c_'.$k, 'money', total: true);
        }
        $cols[] = Column::make('Cəmi xərc', 'total', 'money', total: true);

        return $cols;
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $d = $this->data();
        $late = $d->filter(fn ($r) => ($r['delay'] ?? 0) > 0);

        return [
            ['label' => 'Yük sayı', 'value' => $d->count()],
            ['label' => 'Logistika xərci', 'value' => $d->sum('total'), 'money' => true],
            ['label' => 'Gecikən yük', 'value' => $late->count(), 'tone' => $late->count() ? 'danger' : null],
            ['label' => 'Orta gecikmə', 'value' => $late->count() ? round($late->avg('delay'), 1) : 0, 'suffix' => ' gün'],
        ];
    }

    public function chart(): ?array
    {
        $d = $this->data();
        if ($d->isEmpty()) {
            return null;
        }
        $labels = [];
        $series = [];
        foreach (config('glaust.cost_types') as $k => $label) {
            $sum = round($d->sum('c_'.$k), 2);
            if ($sum > 0) {
                $labels[] = $label;
                $series[] = $sum;
            }
        }

        return $series ? ['type' => 'donut', 'height' => 300, 'money' => true, 'labels' => $labels, 'series' => $series, 'totalLabel' => 'Xərc'] : null;
    }
}
