<?php

namespace App\Tables;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TaskTable extends Table
{
    protected array $searchable = ['title', 'description', 'project.name', 'project.code'];

    protected array $sortable = ['due' => 'due_date', 'title' => 'title', 'created' => 'created_at'];

    protected string $defaultDirection = 'asc';

    public function title(): string
    {
        return 'Tapşırıqlar';
    }

    protected function baseQuery(): Builder
    {
        return Task::query()->with(['project:id,code,name', 'assignee:id,name,email'])
            ->withCount(['checklist', 'checklist as checklist_done_count' => fn ($q) => $q->where('is_done', true), 'comments', 'attachments']);
    }

    public function filters(): array
    {
        return [
            'project_id' => ['label' => 'Layihə', 'type' => 'select', 'options' => Project::whereNotIn('status', ['cancelled'])->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => $p->code.' · '.$p->name])->all()],
            'assignee_id' => ['label' => 'Məsul', 'type' => 'select', 'options' => ['me' => 'Mən'] + User::forTenant()->orderBy('name')->pluck('name', 'id')->all()],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => status_options('task') + ['open' => 'Açıq (tamamlanmamış)']],
            'priority' => ['label' => 'Prioritet', 'type' => 'select', 'options' => status_options('priority')],
            'due' => ['label' => 'Son tarix', 'type' => 'select', 'options' => ['overdue' => 'Gecikmiş', 'today' => 'Bu gün', 'week' => '7 gün ərzində', 'none' => 'Tarixsiz']],
        ];
    }

    protected function applyFilters(Builder $query): void
    {
        $r = $this->request;
        if ($r->filled('project_id')) {
            $query->where('project_id', $r->integer('project_id'));
        }
        if ($r->filled('assignee_id')) {
            $query->where('assignee_id', $r->query('assignee_id') === 'me' ? $r->user()->id : $r->integer('assignee_id'));
        }
        if ($r->query('status') === 'open') {
            $query->where('status', '!=', 'done');
        } elseif ($r->filled('status')) {
            $query->where('status', $r->query('status'));
        }
        if ($r->filled('priority')) {
            $query->where('priority', $r->query('priority'));
        }
        $today = today()->toDateString();
        match ($r->query('due')) {
            'overdue' => $query->where('status', '!=', 'done')->where('due_date', '<', $today),
            'today' => $query->whereDate('due_date', $today),
            'week' => $query->whereBetween('due_date', [$today, today()->addDays(7)->toDateString()]),
            'none' => $query->whereNull('due_date'),
            default => null,
        };
    }

    public function columns(): array
    {
        return [
            Column::make('Tapşırıq', 'title', width: 40),
            Column::make('Layihə', fn ($t) => $t->project ? $t->project->code.' · '.$t->project->name : ''),
            Column::make('Məsul', 'assignee.name'),
            Column::make('Status', fn ($t) => status_label('task', $t->status)),
            Column::make('Prioritet', fn ($t) => status_label('priority', $t->priority)),
            Column::make('Başlama', 'start_date', 'date'),
            Column::make('Son tarix', 'due_date', 'date'),
            Column::make('Plan (saat)', 'estimated_hours', 'number'),
            Column::make('Tamamlanıb', 'completed_at', 'datetime'),
        ];
    }
}
