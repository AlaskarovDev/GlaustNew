<?php

namespace App\Imports;

use App\Models\Counterparty;
use App\Models\Project;
use App\Models\User;
use App\Services\NumberGenerator;
use Illuminate\Validation\Rule;

class ProjectImporter extends Importer
{
    public static function type(): string
    {
        return 'projects';
    }

    public static function title(): string
    {
        return __('Layihələr');
    }

    public static function ability(): string
    {
        return 'projects.import';
    }

    public static function description(): string
    {
        return __('Kod boşdursa avtomatik verilir. Müştəri adı və ya VÖEN-i CRM-də tapılmalıdır.');
    }

    public function fields(): array
    {
        return [
            'code' => ['label' => __('Kod'), 'aliases' => ['layihə kodu', 'code'], 'example' => ''],
            'name' => ['label' => __('Layihənin adı'), 'required' => true, 'aliases' => ['ad', 'layihə', 'name'], 'example' => 'Anbar kompleksinin tikintisi'],
            'counterparty' => ['label' => __('Müştəri'), 'aliases' => ['müştəri voen', 'sifarişçi', 'client'], 'example' => '1234567891'],
            'manager' => ['label' => __('Menecer (email)'), 'aliases' => ['menecer', 'manager'], 'example' => ''],
            'status' => ['label' => 'Status', 'aliases' => ['vəziyyət'], 'example' => 'Aktiv'],
            'priority' => ['label' => __('Prioritet'), 'aliases' => ['priority'], 'example' => 'Orta'],
            'start_date' => ['label' => __('Başlama'), 'aliases' => ['başlama tarixi', 'start'], 'example' => '01.10.2026'],
            'end_date' => ['label' => __('Bitmə'), 'aliases' => ['bitmə tarixi', 'son tarix', 'end'], 'example' => '31.12.2026'],
            'budget' => ['label' => __('Büdcə'), 'aliases' => ['budget'], 'example' => '150000'],
            'currency' => ['label' => __('Valyuta'), 'aliases' => ['currency'], 'example' => 'AZN'],
            'description' => ['label' => __('Təsvir'), 'aliases' => ['qeyd', 'description'], 'example' => ''],
        ];
    }

    protected function normalise(array $raw): array
    {
        $row = parent::normalise($raw);
        $row['status'] = self::option($row['status'] ?? null, status_options('project'), 'planned');
        $row['priority'] = self::option($row['priority'] ?? null, status_options('priority'), 'medium');
        $row['start_date'] = self::date($row['start_date'] ?? null);
        $row['end_date'] = self::date($row['end_date'] ?? null);
        $row['budget'] = parse_number($row['budget'] ?? null) ?? 0;
        $row['currency'] = strtoupper((string) ($row['currency'] ?? '')) ?: 'AZN';

        return $row;
    }

    protected function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:32', Rule::unique('projects', 'code')->where('company_id', tenant()->id)],
            'name' => ['required', 'string', 'max:190'],
            'status' => ['required', Rule::in(array_keys(config('glaust.statuses.project')))],
            'priority' => ['required', Rule::in(array_keys(config('glaust.statuses.priority')))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'budget' => ['numeric', 'min:0'],
            'currency' => ['required', Rule::in(config('glaust.currencies'))],
        ];
    }

    protected function validateRow(array $row): void
    {
        parent::validateRow($row);
        $this->lookups($row);
    }

    private function lookups(array $row): array
    {
        $cp = null;
        if (filled($row['counterparty'] ?? null)) {
            $v = (string) $row['counterparty'];
            $cp = Counterparty::where('voen', preg_replace('/\D/', '', $v) ?: '-')->orWhere('name', $v)->first()
                ?? throw new RowError(__('Müştəri CRM-də tapılmadı: :v1', ['v1' => $v]));
        }
        $manager = null;
        if (filled($row['manager'] ?? null)) {
            $manager = User::forTenant()->where('email', strtolower((string) $row['manager']))->first()
                ?? throw new RowError(__('Menecer tapılmadı: :v1', ['v1' => $row['manager']]));
        }

        return [$cp, $manager];
    }

    protected function persist(array $row): void
    {
        $this->validateRow($row);
        [$cp, $manager] = $this->lookups($row);
        $project = Project::create([
            'code' => $row['code'] ?: app(NumberGenerator::class)->next('project'),
            'name' => $row['name'], 'counterparty_id' => $cp?->id, 'manager_id' => $manager?->id,
            'status' => $row['status'], 'priority' => $row['priority'], 'start_date' => $row['start_date'], 'end_date' => $row['end_date'],
            'budget' => $row['budget'], 'currency' => $row['currency'], 'description' => $row['description'] ?? null,
        ]);
        if ($manager) {
            $project->members()->syncWithoutDetaching([$manager->id]);
        }
    }
}
