<?php

namespace App\Imports;

use App\Models\Counterparty;
use App\Models\Shipment;
use App\Services\NumberGenerator;
use Illuminate\Validation\Rule;

class ShipmentImporter extends Importer
{
    public static function type(): string
    {
        return 'shipments';
    }

    public static function title(): string
    {
        return 'Yüklər';
    }

    public static function ability(): string
    {
        return 'logistics.import';
    }

    public static function description(): string
    {
        return 'Nömrə boşdursa avtomatik verilir. Daşıyıcı CRM-də təchizatçı kimi olmalıdır.';
    }

    public function fields(): array
    {
        return [
            'number' => ['label' => 'Nömrə', 'aliases' => ['yük nömrəsi', 'number'], 'example' => ''],
            'direction' => ['label' => 'İstiqamət', 'required' => true, 'aliases' => ['direction'], 'example' => 'İdxal'],
            'origin' => ['label' => 'Çıxış', 'required' => true, 'aliases' => ['haradan', 'origin', 'from'], 'example' => 'İstanbul'],
            'destination' => ['label' => 'Təyinat', 'required' => true, 'aliases' => ['haraya', 'destination', 'to'], 'example' => 'Bakı'],
            'transport_mode' => ['label' => 'Nəqliyyat', 'aliases' => ['nəqliyyat növü', 'mode'], 'example' => 'Avto'],
            'carrier' => ['label' => 'Daşıyıcı', 'aliases' => ['carrier', 'daşıyıcı voen'], 'example' => ''],
            'vehicle' => ['label' => 'Nəqliyyat vasitəsi', 'aliases' => ['maşın', 'vehicle'], 'example' => '10-AA-100'],
            'container_no' => ['label' => 'Konteyner', 'aliases' => ['konteyner №', 'container'], 'example' => ''],
            'document_no' => ['label' => 'CMR', 'aliases' => ['cmr', 'konosament', 'awb', 'sənəd'], 'example' => ''],
            'cargo_description' => ['label' => 'Yük', 'aliases' => ['yükün təsviri', 'cargo'], 'example' => 'Tikinti materialları'],
            'weight_kg' => ['label' => 'Çəki (kq)', 'aliases' => ['çəki', 'weight'], 'example' => '12000'],
            'loading_date' => ['label' => 'Yüklənmə', 'aliases' => ['yüklənmə tarixi'], 'example' => '01.10.2026'],
            'eta' => ['label' => 'Çatma', 'aliases' => ['gözlənilən çatma', 'eta'], 'example' => '10.10.2026'],
            'status' => ['label' => 'Status', 'aliases' => [], 'example' => 'Yolda'],
        ];
    }

    protected function normalise(array $raw): array
    {
        $row = parent::normalise($raw);
        $row['direction'] = self::option($row['direction'] ?? null, config('glaust.shipment_directions'));
        $row['transport_mode'] = self::option($row['transport_mode'] ?? null, config('glaust.transport_modes'), 'road');
        $row['status'] = self::option($row['status'] ?? null, status_options('shipment'), 'planned');
        $row['loading_date'] = self::date($row['loading_date'] ?? null);
        $row['eta'] = self::date($row['eta'] ?? null);
        $row['weight_kg'] = parse_number($row['weight_kg'] ?? null);

        return $row;
    }

    protected function rules(): array
    {
        return [
            'number' => ['nullable', 'string', 'max:40', Rule::unique('shipments', 'number')->where('company_id', tenant()->id)],
            'direction' => ['required', Rule::in(array_keys(config('glaust.shipment_directions')))],
            'origin' => ['required', 'string', 'max:190'],
            'destination' => ['required', 'string', 'max:190'],
            'transport_mode' => ['required', Rule::in(array_keys(config('glaust.transport_modes')))],
            'status' => ['required', Rule::in(\App\Models\Shipment::FLOW)],
            'loading_date' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function validateRow(array $row): void
    {
        parent::validateRow($row);
        $this->carrier($row);
    }

    private function carrier(array $row): ?Counterparty
    {
        if (blank($row['carrier'] ?? null)) {
            return null;
        }
        $v = (string) $row['carrier'];
        $c = Counterparty::suppliers()->where(fn ($q) => $q->where('voen', preg_replace('/\D/', '', $v) ?: '-')->orWhere('name', $v))->first();

        return $c ?? throw new RowError("Daşıyıcı CRM-də təchizatçı kimi tapılmadı: {$v}");
    }

    protected function persist(array $row): void
    {
        $this->validateRow($row);
        $carrier = $this->carrier($row);
        $s = Shipment::create(array_intersect_key($row, array_flip(['direction', 'origin', 'destination', 'transport_mode', 'vehicle', 'container_no', 'document_no', 'cargo_description', 'weight_kg', 'loading_date', 'eta', 'status']))
            + ['number' => $row['number'] ?: app(NumberGenerator::class)->next('shipment'), 'carrier_id' => $carrier?->id]);
        $s->history()->create(['status' => $s->status, 'note' => 'Excel importu']);
    }
}
