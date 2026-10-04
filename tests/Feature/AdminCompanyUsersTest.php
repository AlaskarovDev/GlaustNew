<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Platform admin: create a company, add its users (password, phone, position, role), edit them. */
class AdminCompanyUsersTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $this->seedPlans();
        $u = new User(['name' => 'Platforma', 'email' => 'root@platform.az', 'password' => 'Secret123', 'is_active' => true]);
        $u->is_super_admin = true;
        $u->save();

        return $u;
    }

    public function test_company_and_users_are_managed_in_admin(): void
    {
        $root = $this->superAdmin();
        $this->actingAs($root)->get(route('admin.companies.create'))->assertOk()->assertSee('Şirkəti yarat');
        $this->post(route('admin.companies.store'), ['name' => 'Glaust Handel', 'subscription_status' => 'active'])->assertSessionHasNoErrors();
        $company = Company::where('name', 'Glaust Handel')->firstOrFail();
        $this->assertSame('active', $company->subscription_status);
        $roles = Role::withoutGlobalScopes()->where('company_id', $company->id)->pluck('id', 'key');
        $this->assertCount(count(config('glaust.system_roles')), $roles, 'system roles created');

        $this->get(route('admin.companies.show', $company))->assertOk()->assertSee('İstifadəçi əlavə et');
        $this->post(route('admin.companies.users.store', $company), [
            '_form' => 'new', 'name' => 'Nigar Quliyeva', 'email' => 'Nigar@Glaust.az', 'password' => 'Glaust2026@',
            'phone' => '+994 50 111 22 33', 'position' => 'Baş mühasib', 'role_id' => $roles['accountant'],
        ])->assertSessionHasNoErrors();
        $u = User::where('email', 'nigar@glaust.az')->firstOrFail();
        $this->assertSame([$company->id, '+994 50 111 22 33', 'Baş mühasib', $roles['accountant']], [$u->company_id, $u->phone, $u->position, $u->role_id]);

        // Another company's role, a taken e-mail and a weak password are refused.
        $other = $this->makeCompany('Başqa MMC', 'admin@basqa.az');
        $foreignRole = Role::withoutGlobalScopes()->where('company_id', $other->company_id)->value('id');
        $this->post(route('admin.companies.users.store', $company), ['name' => 'X', 'email' => 'x@g.az', 'password' => 'Glaust2026@', 'role_id' => $foreignRole])->assertSessionHasErrors('role_id');
        $this->post(route('admin.companies.users.store', $company), ['name' => 'X', 'email' => 'nigar@glaust.az', 'password' => 'Glaust2026@', 'role_id' => $roles['admin']])->assertSessionHasErrors('email');
        $this->post(route('admin.companies.users.store', $company), ['name' => 'X', 'email' => 'y@g.az', 'password' => '123', 'role_id' => $roles['admin']])->assertSessionHasErrors('password');

        // The user signs in with the password the admin set.
        auth()->logout();
        $this->post('/login', ['email' => 'nigar@glaust.az', 'password' => 'Glaust2026@'])->assertRedirect();
        $this->assertAuthenticatedAs($u);
        auth()->logout();

        // Edit: new password, other position, deactivate; an empty password keeps the old one.
        $this->actingAs($root)->put(route('admin.companies.users.update', [$company, $u]), [
            '_form' => 'user-'.$u->id, 'name' => 'Nigar Quliyeva', 'email' => 'nigar@glaust.az', 'password' => 'Yeni2026Pass', 'phone' => '+994 50 111 22 33',
            'position' => 'Maliyyə direktoru', 'role_id' => $roles['manager'], 'is_active' => '0',
        ])->assertSessionHasNoErrors();
        $u->refresh();
        $this->assertSame(['Maliyyə direktoru', $roles['manager'], false], [$u->position, $u->role_id, $u->is_active]);
        $this->assertTrue(\Hash::check('Yeni2026Pass', $u->password));
        $this->put(route('admin.companies.users.update', [$company, $u]), ['name' => 'Nigar', 'email' => 'nigar@glaust.az', 'password' => '', 'role_id' => $roles['manager'], 'is_active' => '1']);
        $this->assertTrue(\Hash::check('Yeni2026Pass', $u->fresh()->password));

        // A company user cannot reach the admin area.
        $this->actingAs($other)->get(route('admin.companies.index'))->assertNotFound();
    }

    public function test_setup_company_command(): void
    {
        $this->seedPlans();
        $this->artisan('glaust:setup-company', ['name' => 'Glaust Handel', '--admin-email' => 'Boss@Glaust.az', '--admin-name' => 'İbrahim', '--admin-password' => 'Glaust2026@', '--super' => true])->assertSuccessful();
        $boss = User::where('email', 'boss@glaust.az')->firstOrFail();
        $this->assertTrue((bool) $boss->is_super_admin);
        $this->assertSame('admin', $boss->role->key);
        $this->actingAs($boss)->get(route('admin.companies.index'))->assertOk()->assertSee('Glaust Handel');
        $this->get(route('dashboard'))->assertOk()->assertSee('Platforma idarəetməsi');

        // Running again is idempotent: the company is reused and the same user is just updated.
        $this->artisan('glaust:setup-company', ['name' => 'Glaust Handel', '--admin-email' => 'boss@glaust.az', '--admin-name' => 'X', '--admin-password' => 'Glaust2026@'])->assertSuccessful();
        $this->assertSame(1, Company::where('name', 'Glaust Handel')->count());
    }

    public function test_setup_company_attaches_the_platform_admin(): void
    {
        $root = $this->superAdmin();
        $this->artisan('glaust:setup-company', ['name' => 'Glaust Handel', '--admin-email' => 'root@platform.az', '--admin-name' => 'İbrahim', '--admin-password' => 'Glaust2026@', '--super' => true])
            ->expectsOutputToContain('Mövcud hesab şirkətə bağlandı')->assertSuccessful();
        $root->refresh();
        $this->assertSame('Glaust Handel', Company::find($root->company_id)->name);
        $this->assertSame('admin', $root->role->key);
        $this->assertTrue((bool) $root->is_super_admin);
        $this->assertTrue(\Hash::check('Glaust2026@', $root->password));

        $this->post('/login', ['email' => 'root@platform.az', 'password' => 'Glaust2026@'])->assertRedirect();
        $this->assertAuthenticatedAs($root);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('admin.companies.index'))->assertOk();

        // Someone else's company user is not taken over.
        $other = $this->makeCompany('Başqa MMC', 'admin@basqa.az');
        $this->artisan('glaust:setup-company', ['name' => 'Glaust Handel', '--admin-email' => 'admin@basqa.az', '--admin-password' => 'Glaust2026@'])->assertFailed();
        $this->assertNotSame(Company::where('name', 'Glaust Handel')->value('id'), $other->fresh()->company_id);
    }
}
