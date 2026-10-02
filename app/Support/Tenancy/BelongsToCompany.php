<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned model: global CompanyScope + company_id filled from the tenant on create.
 * Models with `public bool $tenantOptional = true` (login and mail journals) may also
 * hold platform-level rows with company_id NULL, e.g. a failed login for an unknown email.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            if (! $model->company_id) {
                $model->company_id = app(Tenant::class)->id();
                if (! $model->company_id && ! ($model->tenantOptional ?? false)) {
                    throw new \LogicException(static::class.' created without a tenant context');
                }
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
