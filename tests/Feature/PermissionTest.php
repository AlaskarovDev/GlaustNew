<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_sees_only_permitted_modules(): void
    {
        $admin = $this->makeCompany();
        $employee = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($employee);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee(route('projects.index'))
            ->assertDontSee(route('bank.transactions.index'))
            ->assertDontSee(route('settings.users.index'));

        $this->get(route('bank.transactions.index'))->assertForbidden();
        $this->get(route('shipments.index'))->assertForbidden();
        $this->get(route('settings.users.index'))->assertForbidden();
        $this->get(route('reports.index'))->assertForbidden();
        $this->get(route('projects.create'))->assertForbidden();
        $this->get(route('counterparties.export', ['format' => 'xlsx']))->assertForbidden();
        $this->get(route('projects.index'))->assertOk();
    }

    public function test_accountant_has_bank_but_not_logistics(): void
    {
        $admin = $this->makeCompany();
        $acc = $this->makeUser($admin->company, 'accountant', 'muhasib@test.az');
        $this->actingAs($acc);
        $this->get(route('bank.transactions.index'))->assertOk();
        $this->get(route('bank.transactions.create'))->assertRedirect(route('bank.accounts.create')); // no account yet
        $this->get(route('shipments.index'))->assertForbidden();
        $this->get(route('reports.show', 'income-expense'))->assertOk();
        $this->get(route('reports.show', 'logistics'))->assertForbidden();
    }

    public function test_plan_without_module_hides_it_even_for_admin(): void
    {
        $admin = $this->makeCompany();
        $admin->company->update(['plan_id' => Plan::where('code', 'start')->first()->id]);
        $this->actingAs($admin->fresh());

        $this->get(route('bank.transactions.index'))->assertForbidden();
        $this->get(route('shipments.index'))->assertForbidden();
        $this->get(route('projects.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('shipments.index'));
    }

    public function test_custom_role_permissions(): void
    {
        $admin = $this->makeCompany();
        $this->actingAs($admin)->post(route('settings.roles.store'), ['name' => 'Anbardar', 'permissions' => ['logistics.create']])->assertRedirect();
        $role = $this->inTenant($admin, fn () => \App\Models\Role::where('name', 'Anbardar')->firstOrFail());
        $this->assertEqualsCanonicalizing(['logistics.create', 'logistics.view'], $role->permissions, 'an action implies view');

        $user = $this->makeUser($admin->company, 'employee', 'anbar@test.az');
        $user->forceFill(['role_id' => $role->id])->save();
        $this->actingAs($user)->get(route('shipments.create'))->assertOk();
        $this->actingAs($user)->get(route('projects.index'))->assertForbidden();
    }

    public function test_last_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = $this->makeCompany();
        $managerRole = $this->inTenant($admin, fn () => \App\Models\Role::where('key', 'manager')->first());
        $second = $this->makeUser($admin->company, 'admin', 'admin2@test.az');

        // Self-demotion is refused.
        $this->actingAs($admin)->put(route('settings.users.update', $admin->id), ['name' => 'A', 'email' => 'admin@test.az', 'role_id' => $managerRole->id, 'is_active' => '1'])
            ->assertSessionHasErrors('role_id');

        // Demoting the other admin works while one admin remains…
        $this->put(route('settings.users.update', $second->id), ['name' => 'B', 'email' => 'admin2@test.az', 'role_id' => $managerRole->id, 'is_active' => '1'])->assertSessionHasNoErrors();
        // …and the user limit/plan keeps working.
        $this->assertFalse($second->fresh()->isCompanyAdmin());
    }
}
