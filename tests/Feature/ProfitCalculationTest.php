<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CurrencyRate;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SupplierPayment;
use App\Support\Profit\ProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mənfəətin hesablanması against the company's workbook «ATF (kurslarla) 150726», sheet ATF, row 3:
 * Ellis 916161494 (73 644.80 EUR) → Axios ATFAXI-1204 (9 898 036.68 RUB), act VAM25006583 of 28.01.2025,
 * seller paid 07.01.2025, logistics 979 732.68 RUB paid 10.02.2025 with a 42.93 AZN fee.
 */
class ProfitCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function rates(string $date, float $eur, float $rub): void
    {
        foreach (['EUR' => $eur, 'RUB' => $rub] as $code => $rate) {
            CurrencyRate::create(['currency_code' => $code, 'rate_date' => $date, 'bulletin_date' => $date, 'rate' => $rate, 'nominal' => 1, 'value' => $rate]);
        }
    }

    private function atfRow3(): array
    {
        $admin = $this->makeCompany();
        $this->rates('2025-01-07', 1.7666, 0.015962);   // X: seller paid  (Y, Z)
        $this->rates('2025-01-28', 1.7739, 0.017487);   // BI: act date    (BJ, BK)
        $this->rates('2025-02-10', 1.7533, 0.017526);   // AY: logistics paid (AZ, BA)

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
            SupplierPayment::create(['deal_id' => $deal->id, 'payment_date' => '2025-01-07', 'currency' => 'EUR', 'amount' => 73644.80, 'bank_account_id' => $eur->id,
                'account_currency' => 'EUR', 'cbar_rate' => 1.7666, 'cbar_account_rate' => 1.7666, 'cbar_cross' => 1, 'bank_rate' => 1,
                'account_amount_cbar' => 73644.80, 'account_amount' => 73644.80, 'difference' => 0, 'difference_azn' => 0, 'fee_amount' => 184.11, 'fee_account_amount' => 184.11]);
            $act = LogisticsAct::create(['deal_id' => $deal->id, 'invoice_id' => $inv->id, 'act_number' => 'VAM25006583', 'act_date' => '2025-01-28', 'currency' => 'RUB',
                'amount' => 979732.68, 'cbar_rate' => 0.017487, 'cbar_rub' => 0.017487, 'cbar_eur' => 1.7739,
                'amount_azn' => round(979732.68 * 0.017487, 2), 'amount_rub' => 979732.68, 'amount_eur' => round(979732.68 * 0.017487 / 1.7739, 2)]);
            LogisticsPayment::create(['logistics_act_id' => $act->id, 'deal_id' => $deal->id, 'payment_date' => '2025-02-10', 'act_amount' => 979732.68, 'currency' => 'RUB',
                'cbar_act_rate' => 0.017526, 'cbar_rate' => 0.017526, 'cbar_cross' => 1, 'bank_rate' => 1, 'amount_cbar' => 979732.68, 'amount' => 979732.68,
                'difference' => 0, 'difference_azn' => 0, 'fee_amount' => 2449.33, 'fee_azn' => 42.93, 'bank_account_id' => $rub->id]);

            return $deal;
        });

        return [$admin, $deal];
    }

    public function test_row_matches_the_workbook_step_by_step(): void
    {
        [$admin, $deal] = $this->atfRow3();
        $r = $this->inTenant($admin, fn () => app(ProfitCalculator::class)->deal(Deal::find($deal->id)))['rows'][0];

        // 2. at the act date
        $this->assertEqualsWithDelta(130638.51, $r['act']['BL'], 0.01);
        $this->assertEqualsWithDelta(173086.97, $r['act']['BM'], 0.01);
        $this->assertEqualsWithDelta(42448.46, $r['act']['BN'], 0.01);
        $this->assertEqualsWithDelta(17132.59, $r['act']['BO'], 0.01);
        $this->assertEqualsWithDelta(25315.87, $r['act']['BP'], 0.01);
        // 3. exchange differences to the payment date, fees, net
        $this->assertEqualsWithDelta(130100.90, $r['settle']['AB'], 0.01);
        $this->assertEqualsWithDelta(157992.46, $r['settle']['AC'], 0.01);
        $this->assertEqualsWithDelta(-15094.51, $r['settle']['BQ'], 0.01);
        $this->assertEqualsWithDelta(537.61, $r['settle']['BR'], 0.01);
        $this->assertEqualsWithDelta(17170.79, $r['settle']['BC'], 0.01);
        $this->assertEqualsWithDelta(38.21, $r['settle']['BS'], 0.01);
        $this->assertEqualsWithDelta(325.25, $r['settle']['AJ'], 0.01);
        $this->assertEqualsWithDelta(42.93, $r['settle']['BB'], 0.001);
        $this->assertEqualsWithDelta(10352.58, $r['settle']['BT'], 0.02, 'XALIS MENFEET (BT) of the workbook');
        $this->assertFalse($r['estimated']);
        $this->assertSame('closed', $r['stage']);
        // 1. forecast at N = 1.79, O = 0.0165 (P, Q of the workbook)
        $this->assertEqualsWithDelta(131824.19, $r['forecast']['P'], 0.01);
        $this->assertEqualsWithDelta(163317.61, $r['forecast']['Q'], 0.01);
        // 4. no bank differences recorded → final = net
        $this->assertEqualsWithDelta($r['settle']['BT'], $r['bank']['final'], 0.001);
    }

    public function test_pages_render_and_export(): void
    {
        [$admin, $deal] = $this->atfRow3();
        $this->actingAs($admin);
        $this->get(route('profit.index'))->assertOk()->assertSee('ATF')->assertSee(money(10352.58, 'AZN'));
        $this->get(route('profit.project', $deal->project_id))->assertOk()->assertSee('916161494')->assertSee('Bağlanıb');
        $this->get(route('profit.deal', $deal))->assertOk()->assertSee('BP + BQ + BR − BS − AJ − BB')->assertSee(money(25315.87, 'AZN'));
        $this->get(route('profit.export', $deal->project_id))->assertOk();
        $this->get(route('dashboard'))->assertSee('Mənfəətin hesablanması');

        $employee = $this->makeUser($admin->company, 'employee', 'isci@test.az');
        $this->actingAs($employee)->get(route('profit.index'))->assertForbidden();
    }
}
