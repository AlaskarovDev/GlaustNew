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
        foreach (array_diff(array_keys(config('glaust.analytics')), ['summary', 'cashflow', 'profit', 'balance', 'founders', 'fx', 'projects']) as $key) {
            $this->get(route('analytics.show', $key))->assertOk()->assertSee('aktivləşəcək');
        }
        $this->get(route('analytics.show', 'summary'))->assertOk()->assertDontSee('aktivləşəcək')->assertSee('Hesablanacaq faktura yoxdur');   // live
        $this->get(route('analytics.show', 'profit'))->assertOk()->assertDontSee('aktivləşəcək')->assertSee('HESABAT DÖVRÜNDƏ MƏNFƏƏT (ZƏRƏR)');   // live, empty company
        $this->get(route('analytics.show', 'balance'))->assertOk()->assertDontSee('aktivləşəcək')->assertSee('AKTİVLƏR');
        $this->get(route('analytics.show', 'fx'))->assertOk()->assertDontSee('aktivləşəcək')->assertSee('Məzənnə fərqi yoxdur');
        $this->get(route('analytics.show', 'projects'))->assertOk()->assertDontSee('aktivləşəcək')->assertSee('Bu dövrdə aktı olan Trade yoxdur.');
        // one period for every report: chosen on one, the others follow it in their own form
        $this->get(route('analytics.show', ['profit', 'from' => '2025-03', 'to' => '2025-05']))->assertOk()->assertSee('bütün hesabatlara tətbiq olunur');
        $this->assertSame(['from' => '2025-03', 'to' => '2025-05'], session('analytics.period'));
        $this->get(route('analytics.show', 'founders'))->assertOk()->assertSee('01.03.2025')->assertSee('31.05.2025');
        $this->get(route('analytics.show', 'balance'))->assertOk()->assertSee('31.05.2025');
        $this->get(route('analytics.show', ['cashflow', 'from' => '2025-06']))->assertOk();
        $this->assertSame(['from' => '2025-05', 'to' => '2025-06'], session('analytics.period'), 'a half given keeps the other half, the order fixed');
        $this->get('/analytics/nope')->assertNotFound();
        $this->get(route('reports.index'))->assertOk();

        $employee = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($employee)->get(route('analytics.show', 'cashflow'))->assertForbidden();
    }
}
