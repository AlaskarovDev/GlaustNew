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
            $plan ??= Plan::where('is_active', true)->orderByDesc('max_users')->first();

            $model = Company::create([
                'name' => $company['name'],
                'slug' => $this->uniqueSlug($company['name']),
                'voen' => $company['voen'] ?? null,
                'email' => $company['email'] ?? $admin['email'],
                'phone' => $company['phone'] ?? null,
                'plan_id' => $plan?->id,
                'subscription_status' => 'trial',
                'trial_ends_at' => now()->addDays($trialDays)->toDateString(),
                'settings' => [],
            ]);

            return $this->tenant->runAs($model, function (Company $c) use ($admin) {
                $roles = $this->createSystemRoles();
                $this->createDefaultCategories();

                $user = new User([
                    'name' => $admin['name'],
                    'email' => Str::lower($admin['email']),
                    'password' => $admin['password'],
                    'phone' => $admin['phone'] ?? null,
                    'position' => $admin['position'] ?? 'Direktor',
                    'is_active' => true,
                ]);
                $user->company_id = $c->id;
                $user->role_id = $roles['admin']->id;
                $user->email_verified_at = now();
                $user->save();

                return $user;
            });
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
