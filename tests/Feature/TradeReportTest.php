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

        // by the «Məzənnə fərqi» module: seller paid before the act (advance paid), buyer paid before it
        // (advance received), logistics paid after it (payable)
        $this->assertSame(['Verilmiş avans', 'Alınmış avans'], [$r['TX_SC'], $r['TX_BC']]);
        $this->assertEqualsWithDelta(537.61, $r['TX_S'], 0.01, '73 644.80 × (1.7739 − 1.7666): gain');
        $this->assertEqualsWithDelta(-11570.80, $r['TX_B'], 0.01, '9 898 036.68 × (0.016318 − 0.017487): loss');
        $this->assertEqualsWithDelta(-38.21, $r['TX_L'], 0.01, '979 732.68 × (0.017526 − 0.017487) more paid: loss');
        $this->assertSame([537.61, 11609.01, -11071.4], [$r['TX_P'], $r['TX_N'], $r['TX']]);
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
        $this->assertNull($r['TX_S'], 'nothing paid to the seller by then');
        $this->assertSame('Alınmış avans', $r['TX_BC']);
        $this->assertEqualsWithDelta(9898036.68 * (0.016318 - 0.016), $r['TX_B'], 0.01, 'the advance received, valued at the month end');
        $this->assertNull($r['TX_L'], 'logistics paid later');

        // 31.01.2025: the act is in (28.01) — a finished Trade, as before; the logistics payment (10.02) is still to come
        $r = $this->inTenant($admin, fn () => app(TradeReport::class)->rows(Deal::whereKey($deal->id)->get(), '2025-01-31'))[0];
        $this->assertFalse($r['provisional']);
        $this->assertEqualsWithDelta(537.61, $r['TX_S'], 0.01);
        $this->assertNull($r['TX_L']);

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

    public function test_page_filters_formula_window_and_export(): void
    {
        [$admin, $deal] = $this->row3();
        $this->actingAs($admin);

        $this->get(route('analytics.show', 'summary'))->assertOk()
            ->assertSee('Hesabat cədvəli')->assertSee('916161494')->assertSee('XALİS MƏNFƏƏT')->assertSee('BP + BQ + BR − BS − AJ − BB')
            ->assertSee(money(10352.58))->assertSee('Məzənnə fərqi — Vergi Məcəlləsi ilə')->assertSee(money(11609.01));
        $this->get(route('analytics.show', ['summary', 'project_id' => $deal->project_id, 'deal_id' => $deal->id]))->assertOk()->assertSee('916161494');
        $this->get(route('analytics.show', ['summary', 'project_id' => 999999]))->assertOk()->assertSee('Hesablanacaq faktura yoxdur');
        $x = $this->get(route('analytics.show', ['summary', 'deal_id' => $deal->id, 'format' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
        $this->get(route('analytics.show', 'cashflow'))->assertOk();   // the other pages as before
    }
}
