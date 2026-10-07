<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\SupplierPayment;
use App\Support\Reports\CashflowReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cash flow by month from recorded money — ATF row 3 plus a salary and bank fees, as in the company's «CASH FLOW» sheet. */
class CashflowReportTest extends TestCase
{
    use Concerns\AtfRow3, RefreshDatabase;

    public function test_months_chain_and_lines_follow_the_sheet(): void
    {
        [$admin, $deal] = $this->row3();
        $this->inTenant($admin, function () use ($deal) {
            // the bank vs CBAR results of the exchanges (AL, AM of the sheet)
            CurrencyExchange::where('direction', 'sell')->update(['difference_azn' => 613.68]);
            CurrencyExchange::where('direction', 'buy')->update(['difference_azn' => 915.48]);
            $fee = Category::firstOrCreate(['scope' => 'expense', 'name' => 'Bank komissiyası'], ['color' => '#64748b']);
            $salary = Category::firstOrCreate(['scope' => 'expense', 'name' => 'Əmək haqqı'], ['color' => '#64748b']);
            $e = fn ($cat, $date, $azn) => Expense::create(['expense_date' => $date, 'category_id' => $cat->id, 'description' => 'x', 'amount' => $azn, 'currency' => 'AZN',
                'amount_azn' => $azn, 'status' => 'paid', 'paid_at' => $date, 'payment_method' => 'cash']);
            $transferFee = $e($fee, '2025-01-07', 325.25);                 // the seller transfer's fee (AJ)
            SupplierPayment::where('deal_id', $deal->id)->update(['fee_expense_id' => $transferFee->id]);
            $e($fee, '2025-01-20', 30.00);                                // a domestic bank fee
            $e($salary, '2025-02-28', 728.00);                            // salaries
        });

        $d = $this->inTenant($admin, fn () => (new CashflowReport)->build('2024-12', '2025-02'));
        $this->assertSame(['2024-12', '2025-01', '2025-02'], $d['months']);
        $this->assertSame(['2024-12' => 161516.16, '2025-01' => 0.0, '2025-02' => 0.0], $d['in'], 'W: 9 898 036.68 × 0.016318');
        $lines = collect($d['payments'])->mapWithKeys(fn ($g) => [$g['label'] => $g['values']]);
        $this->assertSame(130100.9, $lines['Satıcılar']['2025-01'], 'D × Y: 73 644.80 × 1.7666');
        $this->assertSame(17170.79, $lines['Logistika']['2025-02'], 'AX × BA: 979 732.68 × 0.017526');
        $this->assertSame(1529.16, $lines['Kurs fərqi']['2025-01'], 'AL + AM: 613.68 + 915.48');
        $this->assertSame(325.25, $lines['Valyuta köçürmə komissiyası']['2025-01']);
        $this->assertSame(30.0, $lines['Ölkədaxili bank komissiyası']['2025-01']);
        $this->assertSame(728.0, $lines['Əmək haqqı']['2025-02']);
        $this->assertSame(['Kurs fərqi', 'Valyuta köçürmə komissiyası'], array_slice(array_column($d['payments'], 'label'), -2), 'the special lines come last');

        // the months chain: closing = opening + in − out, the next month opens with it
        $this->assertSame(['2024-12' => 0.0, '2025-01' => 161516.16, '2025-02' => round(161516.16 - 130100.9 - 1529.16 - 325.25 - 30.0, 2)], $d['opening']);
        $this->assertEqualsWithDelta($d['opening']['2025-02'] - 17170.79 - 728.0, $d['closing']['2025-02'], 0.001);
        // a later range opens with everything before it
        $this->assertSame($d['closing']['2025-01'], $this->inTenant($admin, fn () => (new CashflowReport)->build('2025-02', '2025-02'))['opening']['2025-02']);

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02']))->assertOk()
            ->assertSee('CASH FLOW')->assertSee('Dövrün əvvəlinə qalıq')->assertSee(num(161516.16))->assertSee(num(130100.9))->assertSee('Kurs fərqi');
        $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02', 'project_id' => $deal->project_id]))->assertOk()->assertSee(num(130100.9));
        $x = $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02', 'format' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
        $this->get(route('analytics.show', 'cashflow'))->assertOk();
    }
}
