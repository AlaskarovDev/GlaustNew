<?php

namespace Tests;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fake cbar.az with the real bulletin in tests/Fixtures (downloaded from cbar.az),
     * stamped with the requested date. Dates in $fail answer HTTP 503.
     * Returns a counter of requests per date.
     */
    protected function fakeCbar(array $fail = []): \ArrayObject
    {
        $xml = file_get_contents(base_path('tests/Fixtures/cbar-sample.xml'));
        $hits = new \ArrayObject;
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($xml, $fail, $hits) {
            preg_match('#currencies/(\d\d\.\d\d\.\d{4})\.xml#', $request->url(), $m);
            $d = $m[1] ?? 'x';
            $hits[$d] = ($hits[$d] ?? 0) + 1;
            if (in_array($d, $fail, true)) {
                return Http::response('', 503);
            }

            return Http::response(preg_replace('/Date="[^"]+"/', 'Date="'.$d.'"', $xml, 1), 200, ['Content-Type' => 'application/xml']);
        });

        return $hits;
    }

    protected function seedPlans(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    /** A company on the full plan with its admin user. */
    protected function makeCompany(string $name = 'Test MMC', string $email = 'admin@test.az'): User
    {
        if (! Plan::exists()) {
            $this->seedPlans();
        }

        return app(CompanyProvisioner::class)->create(['name' => $name], ['name' => 'Admin '.$name, 'email' => $email, 'password' => 'Secret123'], Plan::where('code', 'business')->first());
    }

    protected function makeUser(Company $company, string $roleKey, string $email): User
    {
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', $roleKey)->firstOrFail();
        $u = new User(['name' => ucfirst($roleKey).' User', 'email' => $email, 'password' => 'Secret123', 'is_active' => true]);
        $u->company_id = $company->id;
        $u->role_id = $role->id;
        $u->save();

        return $u;
    }

    /** Run code as if inside a request of this user's company. */
    protected function inTenant(User $user, callable $fn): mixed
    {
        return app(Tenant::class)->runAs($user->company, $fn);
    }
}
