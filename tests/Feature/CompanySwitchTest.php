<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Firma seçimi»: one person in several companies — each its own data, panel and role; switching from the header. */
class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_opens_a_company_and_switches_between_them(): void
    {
        $admin = $this->makeCompany('ATF OIL GROUP');
        $a = $admin->company;
        $this->inTenant($admin, fn () => Project::create(['code' => 'P-ATF', 'name' => 'ATF gizli layihəsi', 'status' => 'active']));
        $clerk = $this->makeUser($a, 'employee', 'clerk@test.az');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Şirkətlərim')->assertSee('Yeni şirkət');

        // a new company: the user is its admin and works in it now; its data is its own
        $this->post(route('company.store'), ['name' => 'İkinci Firma MMC', 'voen' => '1234567890'])->assertRedirect(route('dashboard'));
        $admin->refresh();
        $b = Company::where('name', 'İkinci Firma MMC')->firstOrFail();
        $this->assertSame($b->id, (int) $admin->company_id);
        $this->assertTrue($admin->role->is_admin);
        $this->assertSame($b->id, $admin->role->company_id);
        $this->assertSame([$a->plan_id, $a->subscription_status], [$b->plan_id, $b->subscription_status], 'the same subscription');
        $this->get(route('projects.index'))->assertOk()->assertDontSee('ATF gizli layihəsi');
        $this->get(route('dashboard'))->assertOk()->assertSee('İkinci Firma MMC')->assertSee('ATF OIL GROUP')->assertSee('Seçilmiş şirkət')->assertSee('İF');

        // still a user of the first company while working in the second
        $this->assertContains($admin->id, $this->inTenant($clerk, fn () => User::forTenant()->pluck('id')->all()));

        // back: the role of the first company again, its data again
        $this->post(route('company.switch', $a))->assertRedirect(route('dashboard'));
        $admin->refresh();
        $this->assertSame([$a->id, $a->id], [(int) $admin->company_id, $admin->role->company_id]);
        $this->get(route('projects.index'))->assertOk()->assertSee('ATF gizli layihəsi');

        // a role changed here while the person works elsewhere lands on this company only
        $this->post(route('company.switch', $b));
        $admin->refresh();
        $manager = Role::withoutGlobalScopes()->where('company_id', $a->id)->where('key', 'manager')->firstOrFail();
        $this->inTenant($clerk, fn () => $admin->setAccessIn($a->id, $manager->id));
        $admin->refresh();
        $this->assertSame($b->id, $admin->role->company_id, 'the role in the company the user is in stays');
        $this->assertSame($manager->id, $admin->roleIn($a->id)->id);
    }

    public function test_only_members_switch_and_only_admins_open_companies(): void
    {
        $admin = $this->makeCompany('ATF OIL GROUP');
        $other = $this->makeCompany('Başqa MMC', 'other@test.az');
        $clerk = $this->makeUser($admin->company, 'employee', 'clerk@test.az');

        $this->actingAs($clerk)->post(route('company.switch', $other->company))->assertNotFound();
        $this->assertSame($admin->company_id, $clerk->fresh()->company_id);
        $this->post(route('company.store'), ['name' => 'Yox'])->assertForbidden();
        $this->get(route('my-work'))->assertOk()->assertSee('Şirkətlərim')->assertDontSee('Yeni şirkət');
        $this->assertSame(0, Company::where('name', 'Yox')->count());
    }
}
