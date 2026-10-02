<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);
        if ($tenant->bypassed()) {
            return;
        }

        $column = $model->qualifyColumn('company_id');
        $tenant->id() === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($column, $tenant->id());
    }
}
