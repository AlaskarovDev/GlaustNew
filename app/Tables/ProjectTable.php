<?php

namespace App\Tables;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProjectTable extends Table
{
    protected array $searchable = ['code', 'name', 'counterparty.name'];

    protected array $sortable = ['updated' => 'updated_at', 'code' => 'code', 'name' => 'name', 'end' => 'end_date', 'budget' => 'budget'];

    public function title(): string
    {
        return 'Layihələr';
    }

    protected function baseQuery(): Builder
    {
        return Project::query()
            ->with(['counterparty:id,name', 'manager:id,name,email', 'members:id,name,email'])
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($q) => $q->where('status', 'done'),
                'tasks as overdue_tasks_count' => fn ($q) => $q->where('status', '!=', 'done')->where('due_date', '<', today()->toDateString())]);
    }

    public function filters(): array
    {
        return [
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => status_options('project')],
            'priority' => ['label' => 'Prioritet', 'type' => 'select', 'options' => status_options('priority')],
            'manager_id' => ['label' => 'Menecer', 'type' => 'select', 'options' => User::forTenant()->orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function applyFilters(Builder $query): void
    {
        $r = $this->request;
        foreach (['status', 'priority'] as $f) {
            if ($r->filled($f)) {
                $query->where($f, $r->query($f));
            }
        }
        if ($r->filled('manager_id')) {
            $query->where('manager_id', $r->integer('manager_id'));
        }
        if ($r->filled('counterparty_id')) {
            $query->where('counterparty_id', $r->integer('counterparty_id'));
        }
        if ($r->query('mine')) {
            $id = $r->user()->id;
            $query->where(fn ($w) => $w->where('manager_id', $id)->orWhereHas('members', fn ($m) => $m->where('users.id', $id)));
        }
    }

    public function columns(): array
    {
        return [
            Column::make('Kod', 'code'),
            Column::make('Ad', 'name', width: 34),
            Column::make('Müştəri', 'counterparty.name'),
            Column::make('Menecer', 'manager.name'),
            Column::make('Status', fn ($p) => status_label('project', $p->status)),
            Column::make('Prioritet', fn ($p) => status_label('priority', $p->priority)),
            Column::make('Başlama', 'start_date', 'date'),
            Column::make('Bitmə', 'end_date', 'date'),
            Column::make('Büdcə', 'budget', 'money'),
            Column::make('Valyuta', 'currency'),
            Column::make('Tapşırıqlar', 'tasks_count', 'number'),
            Column::make('Tamamlanıb', 'done_tasks_count', 'number'),
            Column::make('İrəliləyiş %', fn ($p) => $p->progress(), 'number'),
        ];
    }
}
