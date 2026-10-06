<?php

namespace Tests\Feature;

use App\Support\FxDifference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Müsbət / mənfi məzənnə fərqi per the Tax Code — the rule set's own examples, the 31.12 revaluation, the page. */
class FxDifferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rule_examples(): void
    {
        // 1) advance for a service: 10 000 € × 2,00 paid, received at 1,50 → 5 000 negative in the receipt year, no 31.12 step
        $r = FxDifference::calc('verilmis_avans', 10000, '2025-12-01', 2.00, '2026-04-15', 1.50, [2025 => 1.80], true);
        $this->assertSame([0.0, 5000.0, 15000.0, 20000.0], [$r['positive'], $r['negative'], $r['recognized'], $r['paid']]);
        $this->assertCount(1, $r['steps']);
        $this->assertSame(['2026-04-15', '219.3'], [$r['steps'][0]['date'], $r['steps'][0]['line']['profit']]);
        $this->assertSame([20000.0, '2025-12-01'], [$r['tax_base'], $r['tax_base_date']], 'VAT / withholding on the manat paid, on the payment day');
        $this->assertEqualsWithDelta($r['paid'], $r['recognized'] + $r['negative'] - $r['positive'], 0.001);

        // 2) advance for goods: 18 400, received at 1,90 → 19 000 → +600 positive
        $r = FxDifference::calc('verilmis_avans', 10000, '2026-01-10', 1.84, '2026-02-10', 1.90);
        $this->assertSame([600.0, 0.0, '214'], [$r['positive'], $r['negative'], $r['steps'][0]['line']['profit']]);

        // 3) import then payment: 40 000 debt paid with 38 000 → 2 000 positive
        $r = FxDifference::calc('alis_borc', 20000, '2026-01-10', 2.00, '2026-03-10', 1.90);
        $this->assertSame([2000.0, 0.0], [$r['positive'], $r['negative']]);

        // 4) payable at year end: 9 260 → 9 425 → 165 negative
        $r = FxDifference::calc('il_sonu_ohdelik', 5000, '2025-11-01', 1.852, '2025-12-31', 1.885);
        $this->assertSame([0.0, 165.0], [$r['positive'], $r['negative']]);

        // 5) currency account at year end: 17 792 → 18 280 → 488 positive
        $r = FxDifference::calc('il_sonu_aktiv', 8000, '2025-11-01', 2.224, '2025-12-31', 2.285);
        $this->assertSame([488.0, 0.0], [$r['positive'], $r['negative']]);

        // received advance: 20 000 received, income 19 000 at delivery → +1 000 positive
        $r = FxDifference::calc('alinmis_avans', 10000, '2026-01-10', 2.00, '2026-02-10', 1.90);
        $this->assertSame([1000.0, 0.0, 19000.0], [$r['positive'], $r['negative'], $r['recognized']]);

        // same day: nothing
        $this->assertSame([], FxDifference::calc('alis_borc', 100, '2026-01-10', 2.0, '2026-01-10', 2.0)['steps']);
    }

    public function test_open_debt_is_revalued_on_31_december_then_from_that_rate(): void
    {
        $r = FxDifference::calc('alis_borc', 20000, '2025-11-10', 2.00, '2026-03-10', 1.90, [2025 => 1.95]);
        $this->assertSame([['2025-12-31', 'positive', 1000.0], ['2026-03-10', 'positive', 1000.0]],
            array_map(fn ($s) => [$s['date'], $s['kind'], $s['amount']], $r['steps']));

        $r = FxDifference::calc('satis_borc', 1000, '2025-11-10', 2.00, '2026-03-10', 2.10, [2025 => 2.20]);
        $this->assertSame([['2025-12-31', 'positive', 200.0], ['2026-03-10', 'negative', 100.0]],
            array_map(fn ($s) => [$s['date'], $s['kind'], $s['amount']], $r['steps']));
    }

    public function test_advance_across_the_year_end_is_split_on_31_december_when_chosen(): void
    {
        // 10 000 € paid 01.12.2025 at 1,9717, received 13.01.2026 at 1,9832, 31.12.2025 at 1,9900
        $r = FxDifference::calc('verilmis_avans', 10000, '2025-12-01', 1.9717, '2026-01-13', 1.9832, [2025 => 1.99], false, true);
        $this->assertSame([['2025-12-31', 2025, 'positive', 183.0], ['2026-01-13', 2026, 'negative', 68.0]],
            array_map(fn ($s) => [$s['date'], $s['year'], $s['kind'], $s['amount']], $r['steps']));
        $this->assertSame(115.0, $r['net'], 'the same 115 in total, only split between the years');
        $this->assertSame([['date' => '2025-12-31', 'rate' => 1.99, 'azn' => 19900.0]], $r['year_ends']);
        $this->assertEqualsWithDelta($r['paid'], $r['recognized'] + $r['negative'] - $r['positive'], 0.001);

        $r = FxDifference::calc('alinmis_avans', 1000, '2025-12-01', 2.00, '2026-01-13', 2.10, [2025 => 2.20], false, true);
        $this->assertSame([['2025-12-31', 'negative', 200.0], ['2026-01-13', 'positive', 100.0]],
            array_map(fn ($s) => [$s['date'], $s['kind'], $s['amount']], $r['steps']));
    }

    public function test_page_calculates_with_cbar_rates_and_lists_30_days(): void
    {
        Http::fake(['*' => Http::response('', 500)]);   // rates come from the stored bulletins only
        foreach (['2025-12-01' => 2.00, '2025-12-31' => 1.80, '2026-04-15' => 1.50, '2026-04-14' => 1.52] as $date => $rate) {
            DB::table('currency_rates')->insert(['currency_code' => 'EUR', 'rate_date' => $date, 'bulletin_date' => $date,
                'rate' => $rate, 'nominal' => 1, 'value' => $rate, 'source' => 'CBAR', 'created_at' => now(), 'updated_at' => now()]);
        }
        $admin = $this->makeCompany();
        $this->actingAs($admin);

        $this->get(route('fx-difference.index'))->assertOk()->assertSee('Layihənin bitmə tarixi');
        $this->get(route('fx-difference.index', ['end_date' => '2026-04-15', 'lines' => [
            ['case' => 'verilmis_avans', 'currency' => 'EUR', 'amount' => '10000', 'date' => '2025-12-01', 'note' => 'Ellis', 'nonres' => '1'],
        ]]))->assertOk()->assertSee("MƏNFİ (xərc)")->assertSee(money(5000))->assertSee('219.3')->assertSee(money(20000))->assertSee('Ellis')
            ->assertSee('31.12.2025')->assertSee(money(2000))->assertSee(money(3000))
            ->assertSee('İl sonu xərc kimi tanınır')->assertSee(money(18000))->assertSee('Layihə sonunda xərc kimi tanınır');   // advance split on 31.12 by default
        $this->get(route('fx-difference.index', ['end_date' => '2026-04-15', 'revalue_advances' => '0', 'lines' => [
            ['case' => 'verilmis_avans', 'currency' => 'EUR', 'amount' => '10000', 'date' => '2025-12-01'],
        ]]))->assertOk()->assertSee(money(5000))->assertDontSee(money(2000));

        // a future end date needs a forecast rate, never another day's
        $this->get(route('fx-difference.index', ['end_date' => today()->addMonth()->toDateString(), 'lines' => [
            ['case' => 'alis_borc', 'currency' => 'EUR', 'amount' => '100', 'date' => '2026-04-15'],
        ]]))->assertOk()->assertSee('proqnoz məzənnəni daxil edin');
        $this->get(route('fx-difference.index', ['end_date' => '2026-04-15', 'lines' => [
            ['case' => 'alis_borc', 'currency' => 'EUR', 'amount' => '100', 'date' => '2026-04-14'],
        ]]))->assertOk()->assertSee('MÜSBƏT (gəlir)');
        $this->get(route('fx-difference.index', ['end_date' => '2026-04-15', 'lines' => [
            ['case' => 'alis_borc', 'currency' => 'EUR', 'amount' => '100', 'date' => '2026-04-15'],
        ]]))->assertOk()->assertSee('məzənnə fərqi yoxdur');   // same day; a line without note / nonres renders too

        $this->get(route('currency.index', ['code' => 'EUR', 'days' => 365]))->assertOk()->assertSee('EUR — gündəlik məzənnələr')->assertSee('14.04.2026');
    }
}
