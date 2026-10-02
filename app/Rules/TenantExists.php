<?php

namespace App\Rules;

use App\Support\Tenancy\Tenant;
use Illuminate\Validation\Rules\Exists;

/**
 * exists:<table>,id restricted to the current company.
 * A plain `exists` rule bypasses Eloquent scopes and would accept another tenant's id.
 */
class TenantExists
{
    public static function in(string $table, string $column = 'id', bool $withTrashed = false): Exists
    {
        $rule = (new Exists($table, $column))->where('company_id', app(Tenant::class)->id() ?? -1);

        return $withTrashed ? $rule : $rule->whereNull('deleted_at');
    }

    public static function plain(string $table, string $column = 'id'): Exists
    {
        return (new Exists($table, $column))->where('company_id', app(Tenant::class)->id() ?? -1);
    }
}
