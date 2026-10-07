<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hesabatlar menu: the seven report pages exist, the group lists them and keeps the older reports. */
class AnalyticsMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_menu_and_pages(): void
    {
        $admin = $this->makeCompany();
        $this->actingAs($admin);

        $page = $this->get(route('analytics.show', 'cashflow'))->assertOk();
        foreach (['Cashflow', 'Alış hesabatları', 'Layihə hesabatları', 'Kurs fərqləri', 'Mənfəət və zərər', 'Xərclər hesabatı', 'Yekun hesabat', 'Digər hesabatlar'] as $label) {
            $page->assertSee($label);
        }
        foreach (array_diff(array_keys(config('glaust.analytics')), ['summary']) as $key) {
            $this->get(route('analytics.show', $key))->assertOk()->assertSee('aktivləşəcək');
        }
        $this->get(route('analytics.show', 'summary'))->assertOk()->assertDontSee('aktivləşəcək')->assertSee('Hesablanacaq faktura yoxdur');   // live
        $this->get('/analytics/nope')->assertNotFound();
        $this->get(route('reports.index'))->assertOk();

        $employee = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($employee)->get(route('analytics.show', 'cashflow'))->assertForbidden();
    }
}
