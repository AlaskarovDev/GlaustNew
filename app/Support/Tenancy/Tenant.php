<?php

namespace App\Support\Tenancy;

use App\Models\Company;

/**
 * Holds the company whose data the current request / command may see.
 *
 * Fail-closed: when no company is set and the bypass is off, tenant-scoped
 * queries return nothing (see CompanyScope). Console jobs must call runAs().
 */
class Tenant
{
    private ?Company $company = null;

    private bool $bypass = false;

    public function set(?Company $company): void
    {
        $this->company = $company;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function bypassed(): bool
    {
        return $this->bypass;
    }

    /** Run a callback scoped to one company, restoring the previous state. */
    public function runAs(Company $company, callable $callback): mixed
    {
        [$prevCompany, $prevBypass] = [$this->company, $this->bypass];
        $this->company = $company;
        $this->bypass = false;
        try {
            return $callback($company);
        } finally {
            [$this->company, $this->bypass] = [$prevCompany, $prevBypass];
        }
    }

    /** Platform-level code (super admin, schedulers) that must see every tenant. */
    public function withoutTenant(callable $callback): mixed
    {
        $prev = $this->bypass;
        $this->bypass = true;
        try {
            return $callback();
        } finally {
            $this->bypass = $prev;
        }
    }
}
