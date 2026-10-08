<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SupplierPayment;
use App\Services\BankLedger;
use App\Support\Reports\TradeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Yekun hesabat against the company's workbook «ATF (kurslarla) 150726», sheet ATF, row 3 — every column:
 * Ellis 916161494 (73 644.80 EUR) → Axios ATFAXI-1204 (9 898 036.68 RUB), money in 29.12.2024, seller paid
 * 07.01.2025, RUB sold at 0.0159 and 73 829 EUR bought at 1.779, act 28.01.2025, logistics paid 10.02.2025.
 */
class TradeReportTest extends TestCase
{
    use Concerns\AtfRow3, RefreshDatabase;

    public function test_every_column_matches_the_workbook(): void
    {
        [$admin, $deal] = $this->row3();
        $r = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get()))[0];

        $sheet = [   // ATF row 3, values as the workbook computes them
            'D' => 73644.8, 'H' => 9898036.68, 'J' => 184.112, 'L' => 1.7694, 'M' => 0.016958, 'N' => 1.79, 'O' => 0.0165,
            'P' => 131824.192, 'Q' => 163317.60522, 'U' => 1.7724, 'V' => 0.016318, 'W' => 161516.16254424,
            'Y' => 1.7666, 'Z' => 0.015962, 'AA' => 130100.90368, 'AC' => 157992.46148616, 'AD' => 157992.46148616, 'AE' => 31415.25886424,
            'AF' => 1.779, 'AG' => 0.0159, 'AH' => 131014.0992, 'AI' => 157378.783212, 'AJ' => 325.2522592, 'AK' => 327.535248,
            'AL' => 613.67827416, 'AM' => 915.4796, 'AO' => 9898036.68, 'AP' => 73829.0, 'AR' => -5938.822008, 'AS' => 810.0928,
            'AX' => 979732.68, 'AZ' => 1.7533, 'BA' => 0.017526, 'BB' => 42.93, 'BC' => 17170.79494968, 'BD' => 9793.41524535,
            'AQ' => 10352.58059728, 'BE' => 8823.42272312,
            'BJ' => 1.7739, 'BK' => 0.017487, 'BL' => 130638.51072, 'BM' => 173086.96742316, 'BN' => 42448.45670316, 'BO' => 17132.58537516,
            'BP' => 25315.871328, 'BQ' => -15094.505937, 'BR' => 537.60704, 'BS' => 38.20957452, 'BT' => 10352.58059728,
        ];
        foreach ($sheet as $col => $want) {
            $this->assertEqualsWithDelta($want, $r[$col], 0.01, "column {$col}");
        }
        $this->assertSame(['916161494', 'ATFAXI-1204', 'VAM25006583_179', 'VAM25006583'], [$r['seller_no'], $r['buyer_no'], $r['BF'], $r['BH']]);

        // exchange differences only by the 1C method; no month chosen — the Trade's whole life
        $this->assertArrayNotHasKey('TX_P', $r);
        $this->assertSame('alıcı: alınmış avans · satıcı: verilmiş avans · logistika: kreditor borcu', $r['C1_H']);
        $this->assertNull($r['C1_M214'], 'the month columns need a month');
        $this->assertSame(['2024-12-29', '2025-01-07', '2025-01-28'], [$r['T']->format('Y-m-d'), $r['X']->format('Y-m-d'), $r['BI']->format('Y-m-d')]);

        // the formula window shows the day behind each rate
        $this->assertSame([['2024-12-29'], ['2025-01-28'], ['2025-01-28']], [$r['_rd']['V'], $r['_rd']['BK'], $r['_rd']['BJ']]);
    }

    /** «Ay sonuna görə hesabla»: a Trade without an act by the month end is valued at that day's CBAR. */
    public function test_unfinished_trades_valued_at_a_month_end(): void
    {
        [$admin, $deal] = $this->row3();
        $this->rates('2024-12-31', 1.7700, 0.016000);
        $this->rates('2025-01-31', 1.7600, 0.018000);

        // 31.12.2024: the act (28.01.2025) has not come, the seller is not paid yet (07.01.2025), the buyer paid on 29.12
        $r = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get(), '2024-12-31'))[0];
        $this->assertTrue($r['provisional']);
        $this->assertSame('2024-12-31', $r['BI']->format('Y-m-d'));
        $this->assertNull($r['C1_D'], 'nothing paid to the seller by then');
        $this->assertSame(round(-9898036.68 * (0.016 - 0.016318), 2), $r['C1_B'], 'the advance received, valued at the month end');
        $this->assertNull($r['C1_E'], 'logistics invoiced later');

        // 31.01.2025: the act is in (28.01) — a finished Trade, as before; the logistics payment (10.02) is still to come
        $r = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get(), '2025-01-31'))[0];
        $this->assertFalse($r['provisional']);
        $this->assertSame(round(73644.8 * (1.7739 - 1.7666), 2), $r['C1_D']);

        // without a month end nothing changes
        $this->assertFalse($this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get()))[0]['provisional']);

        $this->actingAs($admin);
        $end = today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        // the user picks a month and a year; the system takes the month's last day
        $this->get(route('analytics.show', ['summary', 'as_month' => substr($end, 0, 7)]))->assertOk()->assertSee('Ay sonuna görə hesabla')->assertSee(azdate($end).' ay sonuna görə');
        $this->get(route('analytics.show', ['summary', 'as_month' => '2025-02']))->assertOk()->assertSee('28.02.2025 ay sonuna görə');
        $this->get(route('analytics.show', ['summary', 'calc' => 1]))->assertOk()->assertSee(azdate($end).' ay sonuna görə');   // the button alone: the last finished month
        $this->get(route('analytics.show', ['summary', 'as_month' => today()->format('Y-m')]))->assertOk()->assertSee('hələ bitməyib')->assertDontSee('ay sonuna görə —', false);
        $this->get(route('analytics.show', ['summary', 'as_month' => '2025-13']))->assertOk()->assertSee('Ayı seçin');
    }

    /**
     * «1C metodu ilə məzənnə fərqi»: every currency balance — advances too — revalued at CBAR from the day it arises,
     * at every month end and on the day it closes; each step a posting, positives to 214, negatives to 219.3, no netting.
     */
    public function test_one_c_method_at_a_month_end(): void
    {
        [$admin, $deal] = $this->row3();
        $this->rates('2024-12-31', 1.7700, 0.016000);
        $this->rates('2025-01-31', 1.7600, 0.018000);
        $r = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get(), null, '2025-01-31'))[0];

        $H = 9898036.68;
        // A) roubles in 29.12 → sold 07.01: the 31.12 step is 2024's, January gets 31.12 → 07.01
        $this->assertSame(round($H * (0.015962 - 0.016), 2), $r['C1_A']);
        // B) money before the act: an advance received (liability) 29.12 → 28.01; January: 31.12 → 28.01
        $this->assertSame(round(-$H * (0.017487 - 0.016), 2), $r['C1_B']);
        $this->assertStringContainsString('alıcı: alınmış avans', $r['C1_H']);
        // C) euros bought and paid the same day: no posting
        $this->assertNull($r['C1_C']);
        // D) paid before the act: an advance given (asset) 07.01 → 28.01 — revalued too
        $this->assertSame(round(73644.8 * (1.7739 - 1.7666), 2), $r['C1_D']);
        // E) logistics invoice 28.01, paid 10.02: a payable, open at 31.01
        $this->assertSame(round(-979732.68 * (0.018 - 0.017487), 2), $r['C1_E']);

        $this->assertSame(round(73644.8 * (1.7739 - 1.7666), 2), $r['C1_P'], '214: the positive postings');
        $this->assertEqualsWithDelta(abs(round($H * (0.015962 - 0.016), 2)) + abs(round(-$H * (0.017487 - 0.016), 2)) + abs(round(-979732.68 * 0.000513, 2)), $r['C1_N'], 0.001, '219.3: the negative ones, not netted');
        $this->assertSame([$r['C1_P'], $r['C1_N']], [$r['C1_M214'], $r['C1_M219']], 'January is the whole period here');
        $this->assertSame(round(9898036.68 * (0.0159 - 0.015962) + 73829 * (1.7666 - 1.779), 2), $r['C1_FX'], 'conversion: bank against CBAR, apart');
        $this->assertCount(4, $r['_c1']);

        // February: the year's running total; the 31.01 → 10.02 logistics step lands in February
        $this->rates('2025-02-28', 1.7500, 0.019000);
        $f = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get(), null, '2025-02-28'))[0];
        $feb = round(-979732.68 * (0.017526 - 0.018), 2);   // + : the rouble fell back by the payment day
        $this->assertSame($feb, $f['C1_M214']);
        $this->assertSame(round($r['C1_P'] + $feb, 2), $f['C1_P']);

        // no month: the whole life — every posting, none filtered by the period
        $w = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get()))[0];
        $diffs = array_column($w['_c1'], 'diff');
        $this->assertCount(7, $diffs, 'A and B: 29.12 → 31.12 → close; D; E: 28.01 → 31.01 → 10.02');
        $this->assertSame(round(array_sum(array_filter($diffs, fn ($d) => $d > 0)), 2), $w['C1_P']);
        $this->assertSame(round(-array_sum(array_filter($diffs, fn ($d) => $d < 0)), 2), $w['C1_N']);
        $this->assertSame(round(9898036.68 * 0.000318, 2) + 537.61 + round(979732.68 * 0.000474, 2), $w['C1_P']);

        // the page: no button any more; a month adds the 1C detail columns
        $this->actingAs($admin);
        $this->get(route('analytics.show', 'summary'))->assertOk()->assertDontSee('1C metodu ilə məzənnə fərqi')
            ->assertSee('Müsbət məzənnə fərqi (214)')->assertDontSee('1C: seçilmiş ay — 219.3')->assertSee('Yazılışlar (Trade-in bütün müddəti)');
        $this->get(route('analytics.show', ['summary', 'as_month' => '2025-01']))->assertOk()
            ->assertSee('Müsbət məzənnə fərqi (214)')->assertSee('1C: seçilmiş ay — 219.3')->assertSee('Yazılışlar (dövr: 1 yanvar → ay sonu)');
    }

    public function test_page_filters_formula_window_and_export(): void
    {
        [$admin, $deal] = $this->row3();
        $this->actingAs($admin);

        $this->get(route('analytics.show', 'summary'))->assertOk()
            ->assertSee('Hesabat cədvəli')->assertSee('916161494')->assertSee('XALİS MƏNFƏƏT')->assertSee('BP + BQ + BR − BS − AJ − BB')
            ->assertSee(money(10352.58))->assertSee('Məzənnə fərqi — 214 / 219.3')->assertDontSee('Vergi Məcəlləsi');
        $this->get(route('analytics.show', ['summary', 'project_id' => $deal->project_id, 'deal_id' => $deal->id]))->assertOk()->assertSee('916161494');
        $this->get(route('analytics.show', ['summary', 'project_id' => 999999]))->assertOk()->assertSee('Hesablanacaq faktura yoxdur');
        $x = $this->get(route('analytics.show', ['summary', 'deal_id' => $deal->id, 'format' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
        $this->get(route('analytics.show', 'cashflow'))->assertOk();   // the other pages as before
    }
}
