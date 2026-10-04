<?php

namespace App\Tables;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProjectTable extends Table
{
    protected array $searchable = ['code', 'name', 'counterparty.name', 'supplier.name'];

    protected array $sortable = ['updated' => 'updated_at', 'code' => 'code', 'name' => 'name', 'end' => 'end_date', 'budget' => 'budget'];

    public function title(): string
    {
        return 'Layihələr';
    }

    protected function baseQuery(): Builder
    {
        return Project::query()
            ->with(['counterparty:id,name', 'supplier:id,name', 'saleContract:id,number,amount_azn', 'purchaseContract:id,number,amount_azn', 'manager:id,name,email', 'members:id,name,email',
                // for the forecast profit: commission on the seller invoices, expenses booked on the deals
                'deals' => fn ($q) => $q->select('id', 'project_id', 'status')->withSum('expenses', 'amount_azn')
                    ->with('invoices:id,deal_id,type,status,commission_total,currency')])
            ->withCount(['deals', 'deals as active_deals_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])]);
    }

    public function filters(): array
    {
        return [
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
            $id = $r->integer('counterparty_id');
            $query->where(fn ($w) => $w->where('counterparty_id', $id)->orWhere('supplier_id', $id));
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
            Column::make('Satan tərəf', 'supplier.name'),
            Column::make('Alan tərəf', 'counterparty.name'),
            Column::make('Məsul şəxs', 'manager.name'),
            Column::make('Sövdələşmələr', 'deals_count', 'number', total: true),
            Column::make('Davam edən', 'active_deals_count', 'number', total: true),
            Column::make('Proqnoz mənfəət (AZN)', fn ($p) => \App\Support\ProjectForecast::profit($p->deals), 'money', total: true),
        ];
    }
}
