<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Expense;
use App\Services\BankLedger;
use App\Support\Reports\FounderReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Təsisçi hesabatı: a Trade counts in the month of its act; the company's running costs only for the whole company. */
class FounderReportTest extends TestCase
{
    use Concerns\AtfRow3, RefreshDatabase;

    public function test_revenue_expenses_and_profit_by_period_project_and_trade(): void
    {
        [$admin, $deal] = $this->row3();
        $this->inTenant($admin, function () {
            $salary = Category::create(['scope' => 'expense', 'name' => 'Əmək haqqı', 'color' => '#64748b']);
            $tax = Category::create(['scope' => 'expense', 'name' => 'Mənfəət Vergisi', 'color' => '#64748b', 'pl_line' => 'none']);
            foreach ([[$salary, 728], [$tax, 21400]] as [$c, $v]) {
                Expense::create(['expense_date' => '2025-01-20', 'category_id' => $c->id, 'description' => 'x', 'amount' => $v, 'currency' => 'AZN', 'amount_azn' => $v, 'status' => 'paid', 'paid_at' => '2025-01-20', 'payment_method' => 'cash']);
            }
            $interest = Category::create(['scope' => 'income', 'name' => 'Faiz gəlirləri (kapital)', 'color' => '#64748b', 'pl_line' => '212']);
            app(BankLedger::class)->record(BankAccount::where('currency', 'AZN')->first(), ['direction' => 'in', 'transaction_date' => '2025-01-31', 'amount' => 977.77, 'category_id' => $interest->id, 'purpose' => 'Faiz']);
        });

        $d = $this->inTenant($admin, fn () => (new FounderReport)->build('2025-01-01', '2025-01-31'));
        // the Trade: act 28.01 — sale at the payment day's CBAR, profit = the Yekun hesabat's BE
        $this->assertEqualsWithDelta(9898036.68 * 0.015962, $d['tradeRevenue'], 0.01);
        $this->assertSame(1, $d['tradeCount']);
        $this->assertEqualsWithDelta(8823.4263 - 728 + 977.77, $d['profit'], 0.01, 'the Trade, less the salary, plus the interest — the profit tax is not an expense');
        $this->assertSame(977.77, $d['otherIncome']);
        $this->assertSame(728.0, $d['parts']['overhead']);
        $this->assertSame(['Əmək haqqı' => 728.0], $d['overheads']);
        $this->assertEqualsWithDelta($d['revenue'] - $d['expenses'], $d['profit'], 0.01);
        $this->assertEqualsWithDelta($d['profit'], $d['months']['2025-01']['profit'], 0.01);
        $this->assertSame(0.0, $d['previous']['profit'], 'December: nothing closed');

        // a project / Trade: the Trade alone, no running costs
        $p = $this->inTenant($admin, fn () => (new FounderReport)->build('2025-01-01', '2025-01-31', $deal->project_id));
        $this->assertEqualsWithDelta(8823.4263, $p['profit'], 0.01, 'the Yekun hesabat BE of the Trade');
        $this->assertFalse($p['company']);
        $t = $this->inTenant($admin, fn () => (new FounderReport)->build('2025-01-01', '2025-01-31', null, $deal->id));
        $this->assertSame([1, $deal->code], [$t['tradeCount'], $t['trades'][0]['code']]);

        // before the act: in the pipeline, not in the result
        $dec = $this->inTenant($admin, fn () => (new FounderReport)->build('2024-12-01', '2024-12-31', $deal->project_id));
        $this->assertSame([0, 1], [$dec['tradeCount'], $dec['open']['count']]);

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['founders', 'from' => '2025-01-01', 'to' => '2025-01-31']))->assertOk()
            ->assertSee('Təsisçi hesabatı')->assertSee('Xalis mənfəət')->assertSee('Ən gəlirli Trade-lər')->assertSee($deal->code)->assertSee('Şirkətin cari xərcləri');
        $this->get(route('analytics.show', ['founders', 'from' => '2030-01-01', 'to' => '2030-01-31']))->assertOk()->assertSee('Bu dövrdə bağlanmış Trade yoxdur');
        $this->get(route('analytics.show', ['founders', 'from' => 'bad', 'project_id' => $deal->project_id]))->assertOk();
    }
}
