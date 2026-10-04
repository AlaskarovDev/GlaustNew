<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates a tenant with its system roles, default categories and first admin. */
class CompanyProvisioner
{
    public function __construct(private Tenant $tenant) {}

    public function create(array $company, array $admin, ?Plan $plan = null, int $trialDays = 14): User
    {
        return DB::transaction(function () use ($company, $admin, $plan, $trialDays) {
            $model = $this->createCompany($company + ['email' => $admin['email']], $plan, 'trial', $trialDays);
            $adminRole = Role::withoutGlobalScopes()->where('company_id', $model->id)->where('key', 'admin')->firstOrFail();

            return $this->addUser($model, $admin + ['position' => $admin['position'] ?? 'Direktor'], $adminRole->id);
        });
    }

    /** A tenant with its system roles and default categories, without users (the platform admin adds them). */
    public function createCompany(array $company, ?Plan $plan = null, string $status = 'trial', int $trialDays = 14): Company
    {
        return DB::transaction(function () use ($company, $plan, $status, $trialDays) {
            $plan ??= Plan::where('is_active', true)->orderByDesc('max_users')->first();
            $model = Company::create([
                'name' => $company['name'],
                'slug' => $this->uniqueSlug($company['name']),
                'voen' => $company['voen'] ?? null,
                'email' => $company['email'] ?? null,
                'phone' => $company['phone'] ?? null,
                'address' => $company['address'] ?? null,
                'plan_id' => $plan?->id,
                'subscription_status' => $status,
                'trial_ends_at' => $status === 'trial' ? now()->addDays($trialDays)->toDateString() : null,
                'settings' => [],
            ]);
            $this->tenant->runAs($model, function () {
                $this->createSystemRoles();
                $this->createDefaultCategories();
            });

            return $model;
        });
    }

    /** A user of $company (password set by whoever creates it; e-mail treated as verified). */
    public function addUser(Company $company, array $data, int $roleId): User
    {
        return $this->tenant->runAs($company, function (Company $c) use ($data, $roleId) {
            $user = new User([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'position' => $data['position'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $user->company_id = $c->id;
            $user->role_id = $roleId;
            $user->email_verified_at = now();
            $user->save();

            return $user;
        });
    }

    /** @return array<string, Role> */
    public function createSystemRoles(): array
    {
        $roles = [];
        foreach (config('glaust.system_roles') as $key => $def) {
            $roles[$key] = Role::firstOrCreate(['key' => $key], [
                'name' => $def['name'],
                'is_admin' => $def['is_admin'] ?? false,
                'is_system' => true,
                'permissions' => $def['permissions'],
            ]);
        }

        return $roles;
    }

    public function createDefaultCategories(): void
    {
        $defaults = [
            'bank' => [
                ['Satışdan daxilolma', '#0f9d8a'], ['Avans', '#3b82f6'], ['Təchizatçıya ödəniş', '#f59e0b'],
                ['Əmək haqqı', '#8b5cf6'], ['Vergi və rüsumlar', '#ef4444'], ['İcarə', '#64748b'],
                ['Kommunal xərclər', '#14b8a6'], ['Bank xidməti', '#94a3b8'], ['Logistika xərci', '#f97316'], ['Digər', '#6b7280'],
            ],
        ];
        foreach ($defaults as $scope => $items) {
            foreach ($items as [$name, $color]) {
                Category::firstOrCreate(['scope' => $scope, 'name' => $name], ['color' => $color]);
            }
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(strtr($name, ['ə' => 'e', 'Ə' => 'E'])) ?: 'company';
        $slug = $base;
        $i = 2;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
