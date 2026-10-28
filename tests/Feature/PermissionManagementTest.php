<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** İcazələrin idarə olunması: every role in one matrix; a user's own grants and denials on top of the role. */
class PermissionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_matrix_and_a_users_own_exceptions(): void
    {
        $admin = $this->makeCompany();
        $worker = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($admin);

        $this->get(route('settings.permissions.index'))->assertOk()->assertSee('İcazələrin idarə olunması')->assertSee('Rollar üzrə');
        $this->get(route('settings.permissions.index', ['tab' => 'users']))->assertOk()->assertSee($worker->email);

        // the matrix: the employee role may now also delete projects (and sees them, as before)
        $employee = $this->inTenant($admin, fn () => Role::where('id', $worker->role_id)->firstOrFail());
        $matrix = [$employee->id => array_merge($employee->permissions, ['projects.delete'])];
        $this->put(route('settings.permissions.roles'), ['roles' => $matrix])->assertSessionHas('success');
        $this->assertContains('projects.delete', $employee->fresh()->permissions);
        $this->assertTrue($worker->fresh()->can('projects.delete'));

        // the user's own: bank.update granted (bank.view comes with it), projects.view denied (the whole module goes)
        $this->get(route('settings.permissions.user', $worker))->assertOk()->assertSee('Qadağan et')->assertSee('İcazə ver');
        $this->put(route('settings.permissions.user.update', $worker), ['p' => ['bank.update' => 'grant', 'projects.view' => 'deny', 'crm.view' => 'role']])
            ->assertRedirect(route('settings.permissions.user', $worker));
        $w = User::find($worker->id);
        $this->assertSame(['grant' => ['bank.update', 'bank.view'], 'deny' => ['projects.view']], $w->permission_overrides);
        $this->assertTrue($w->can('bank.view'));
        $this->assertTrue($w->can('bank.update'));
        $this->assertFalse($w->can('bank.delete'));
        $this->assertFalse($w->can('projects.view'));
        $this->assertFalse($w->can('projects.delete'), 'no view — no action on the module');
        $this->assertTrue($w->can('crm.view'), 'as the role says');

        $this->actingAs($w)->get(route('bank.transactions.index'))->assertOk();
        $this->actingAs($w)->get(route('projects.index'))->assertForbidden();
        $this->actingAs($w)->get(route('settings.permissions.index'))->assertForbidden();

        // an admin is never narrowed, nobody changes their own
        $this->actingAs($admin);
        $this->put(route('settings.permissions.user.update', $admin), ['p' => ['projects.view' => 'deny']])->assertSessionHas('error');
        $this->assertTrue(User::find($admin->id)->can('projects.view'));
        // back to the role
        $this->put(route('settings.permissions.user.update', $worker), ['p' => []]);
        $this->assertNull(User::find($worker->id)->permission_overrides);
    }
}
