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
    use RefreshDatabase;

    private function rates(string $date, float $eur, float $rub): void
    {
        foreach (['EUR' => $eur, 'RUB' => $rub] as $code => $rate) {
            DB::table('currency_rates')->insert(['currency_code' => $code, 'rate_date' => $date, 'bulletin_date' => $date,
                'rate' => $rate, 'nominal' => 1, 'value' => $rate, 'source' => 'CBAR', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function row3(): array
    {
        $admin = $this->makeCompany();
        Http::fake(fn () => Http::response('', 503));   // only the stored rates count
        $this->rates('2024-12-27', 1.7694, 0.016958);   // K: our invoice   (L, M)
        $this->rates('2024-12-29', 1.7724, 0.016318);   // T: money in      (U, V)
        $this->rates('2025-01-07', 1.7666, 0.015962);   // X: seller paid   (Y, Z)
        $this->rates('2025-01-28', 1.7739, 0.017487);   // BI: act          (BJ, BK)
        $this->rates('2025-02-10', 1.7533, 0.017526);   // AY: logistics paid (AZ)

        $deal = $this->inTenant($admin, function () {
            $project = Project::create(['code' => 'ATF', 'name' => 'ATF', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);
            $deal = Deal::create(['project_id' => $project->id, 'code' => 'TR-1', 'title' => 'Ellis → Axios', 'deal_date' => '2024-12-27', 'currency' => 'EUR', 'status' => 'active']);
            $inv = Invoice::create(['project_id' => $project->id, 'deal_id' => $deal->id, 'type' => 'supplier', 'number' => '916161494', 'invoice_date' => '2024-12-27',
                'currency' => 'EUR', 'total' => 73644.80, 'cbar_rate' => 1.7694, 'total_azn' => 130307.11, 'status' => 'confirmed',
                'fx_source' => 'forecast', 'fx_date' => '2024-12-27', 'fx_base_azn' => 1.79, 'fx_target_azn' => 0.0165, 'fx_rate' => 1.79 / 0.0165]);
            SalesDocument::create(['deal_id' => $deal->id, 'project_id' => $project->id, 'source_invoice_id' => $inv->id, 'kind' => 'proforma', 'number' => 'ATFAXI-1204',
                'doc_date' => '2024-12-27', 'currency' => 'RUB', 'lines' => [['n' => 1, 'description' => 'Goods', 'quantity' => 1, 'unit_price' => 9898036.68, 'total' => 9898036.68]], 'total' => 9898036.68]);
            $rub = BankAccount::create(['name' => 'RUB', 'bank_name' => 'Bank', 'currency' => 'RUB', 'opening_balance' => 0, 'is_active' => true]);
            $eur = BankAccount::create(['name' => 'EUR', 'bank_name' => 'Bank', 'currency' => 'EUR', 'opening_balance' => 0, 'is_active' => true]);
            $azn = BankAccount::create(['name' => 'AZN', 'bank_name' => 'Bank', 'currency' => 'AZN', 'opening_balance' => 0, 'is_active' => true]);
            app(BankLedger::class)->record($rub, ['direction' => 'in', 'transaction_date' => '2024-12-29', 'amount' => 9898036.68, 'deal_id' => $deal->id, 'project_id' => $project->id]);
            SupplierPayment::create(['deal_id' => $deal->id, 'payment_date' => '2025-01-07', 'currency' => 'EUR', 'amount' => 73644.80, 'bank_account_id' => $eur->id,
                'account_currency' => 'EUR', 'cbar_rate' => 1.7666, 'cbar_account_rate' => 1.7666, 'cbar_cross' => 1, 'bank_rate' => 1,
                'account_amount_cbar' => 73644.80, 'account_amount' => 73644.80, 'difference' => 0, 'difference_azn' => 0, 'fee_amount' => 184.112, 'fee_account_amount' => 184.11]);
            $x = fn ($dir, $cur, $amount, $cbar, $bank) => CurrencyExchange::create(['exchange_date' => '2025-01-07', 'direction' => $dir, 'currency' => $cur, 'amount' => $amount,
                'counter_currency' => 'AZN', 'cbar_rate' => $cbar, 'cbar_counter_rate' => 1, 'cbar_cross' => $cbar, 'bank_rate' => $bank,
                'counter_amount_cbar' => round($amount * $cbar, 2), 'counter_amount' => round($amount * $bank, 2), 'difference' => 0, 'difference_azn' => 0,
                'from_account_id' => $azn->id, 'to_account_id' => $azn->id, 'project_id' => $deal->project_id, 'deal_id' => $deal->id]);
            $x('sell', 'RUB', 9898036.68, 0.015962, 0.0159);
            $x('buy', 'EUR', 73829, 1.7666, 1.779);
            $act = LogisticsAct::create(['deal_id' => $deal->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'VAM25006583_179', 'logistics_invoice_date' => '2025-01-28',
                'act_number' => 'VAM25006583', 'act_date' => '2025-01-28', 'currency' => 'RUB',
                'amount' => 979732.68, 'cbar_rate' => 0.017487, 'cbar_rub' => 0.017487, 'cbar_eur' => 1.7739,
                'amount_azn' => round(979732.68 * 0.017487, 2), 'amount_rub' => 979732.68, 'amount_eur' => round(979732.68 * 0.017487 / 1.7739, 2)]);
            LogisticsPayment::create(['logistics_act_id' => $act->id, 'deal_id' => $deal->id, 'payment_date' => '2025-02-10', 'act_amount' => 979732.68, 'currency' => 'RUB',
                'cbar_act_rate' => 0.017526, 'cbar_rate' => 0.017526, 'cbar_cross' => 1, 'bank_rate' => 1, 'amount_cbar' => 979732.68, 'amount' => 979732.68,
                'difference' => 0, 'difference_azn' => 0, 'fee_amount' => 2449.33, 'fee_azn' => 42.93, 'bank_account_id' => $rub->id]);

            return $deal;
        });

        return [$admin, $deal];
    }

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
        $this->get(route('analytics.show', ['summary', 'as_of' => $end]))->assertOk()->assertSee('Ay sonuna görə hesabla')->assertSee(azdate($end).' ay sonuna görə');
        $this->get(route('analytics.show', ['summary', 'as_of' => '2019-01-31']))->assertOk()->assertDontSee('2019 ay sonuna görə');   // only the offered month ends
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
