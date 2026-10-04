<?php

namespace App\Imports;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Validation\Rule;

class TaskImporter extends Importer
{
    public static function type(): string
    {
        return 'tasks';
    }

    public static function title(): string
    {
        return __('Tapşırıqlar');
    }

    public static function ability(): string
    {
        return 'projects.import';
    }

    public static function description(): string
    {
        return __('Layihə kodu və məsul şəxsin emaili sistemdə olmalıdır.');
    }

    public function fields(): array
    {
        return [
            'title' => ['label' => __('Tapşırıq'), 'required' => true, 'aliases' => ['başlıq', 'ad', 'title', 'task'], 'example' => 'Layihə sənədlərini hazırlamaq'],
            'project' => ['label' => __('Layihə kodu'), 'aliases' => ['layihə', 'project'], 'example' => 'PRJ-2026-0001'],
            'assignee' => ['label' => __('Məsul (email)'), 'aliases' => ['məsul', 'icraçı', 'assignee'], 'example' => ''],
            'status' => ['label' => 'Status', 'aliases' => [], 'example' => 'Görüləcək'],
            'priority' => ['label' => __('Prioritet'), 'aliases' => [], 'example' => 'Yüksək'],
            'start_date' => ['label' => __('Başlama'), 'aliases' => ['başlama tarixi'], 'example' => '01.10.2026'],
            'due_date' => ['label' => __('Son tarix'), 'aliases' => ['deadline', 'bitmə'], 'example' => '15.10.2026'],
            'estimated_hours' => ['label' => __('Plan (saat)'), 'aliases' => ['saat', 'hours'], 'example' => '8'],
            'description' => ['label' => __('Təsvir'), 'aliases' => ['qeyd'], 'example' => ''],
        ];
    }

    protected function normalise(array $raw): array
    {
        $row = parent::normalise($raw);
        $row['status'] = self::option($row['status'] ?? null, status_options('task'), 'todo');
        $row['priority'] = self::option($row['priority'] ?? null, status_options('priority'), 'medium');
        $row['start_date'] = self::date($row['start_date'] ?? null);
        $row['due_date'] = self::date($row['due_date'] ?? null);
        $row['estimated_hours'] = parse_number($row['estimated_hours'] ?? null);

        return $row;
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(array_keys(config('glaust.statuses.task')))],
            'priority' => ['required', Rule::in(array_keys(config('glaust.statuses.priority')))],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function validateRow(array $row): void
    {
        parent::validateRow($row);
        $this->lookups($row);
    }

    private function lookups(array $row): array
    {
        $project = filled($row['project'] ?? null)
            ? (Project::where('code', $row['project'])->first() ?? throw new RowError(__('Layihə tapılmadı: :v1', ['v1' => $row['project']])))
            : null;
        $user = filled($row['assignee'] ?? null)
            ? (User::forTenant()->where('email', strtolower((string) $row['assignee']))->first() ?? throw new RowError(__('İstifadəçi tapılmadı: :v1', ['v1' => $row['assignee']])))
            : null;

        return [$project, $user];
    }

    protected function persist(array $row): void
    {
        $this->validateRow($row);
        [$project, $user] = $this->lookups($row);
        Task::create([
            'title' => $row['title'], 'project_id' => $project?->id, 'assignee_id' => $user?->id, 'status' => $row['status'],
            'priority' => $row['priority'], 'start_date' => $row['start_date'], 'due_date' => $row['due_date'],
            'estimated_hours' => $row['estimated_hours'], 'description' => $row['description'] ?? null,
        ]);
    }
}
