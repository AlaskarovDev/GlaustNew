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

        // every figure carries the movements behind it, for the popup
        $seller = collect($d['payments'])->firstWhere('label', 'Satıcılar');
        $this->assertCount(1, $seller['details']['2025-01']);
        $this->assertSame(130100.9, $seller['details']['2025-01'][0]['v']);
        $this->assertStringContainsString(num(73644.8).' EUR × 1,7666', $seller['details']['2025-01'][0]['c']);
        $this->assertStringContainsString('Trade '.$deal->code, $seller['details']['2025-01'][0]['t']);
        $fx = collect($d['payments'])->firstWhere('label', 'Kurs fərqi');
        $this->assertSame([613.68, 915.48], collect($fx['details']['2025-01'])->pluck('v')->sort()->values()->all());
        $this->assertSame(['in' => 0.0, 'out' => 0.0, 'other' => 0.0], $d['before']);

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02']))->assertOk()
            ->assertSee('Rəqəmə toxunun — necə yarandığı açılacaq.')->assertSee('Satıcıya ödəniş')->assertSee('Cəmi gəlirlər − Cəmi ödənişlər')
            ->assertSee('CASH FLOW')->assertSee('Dövrün əvvəlinə qalıq')->assertSee(num(161516.16))->assertSee(num(130100.9))->assertSee('Kurs fərqi');
        $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02', 'project_id' => $deal->project_id]))->assertOk()->assertSee(num(130100.9));
        $x = $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02', 'format' => 'xlsx']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
        $this->get(route('analytics.show', 'cashflow'))->assertOk();
    }

    /**
     * «Valyutanın yenidən qiymətləndirilməsi»: the January 2025 example — the December rouble lot sold on 07.01, the January
     * lot sold the next day, euros bought and paid out with a remainder revalued at the month end.
     */
    public function test_currency_revaluation_by_lots_and_month_ends(): void
    {
        $admin = $this->makeCompany();
        \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response('', 503));   // only the stored rates
        $this->rates('2024-12-31', 1.7700, 0.016318);   // 31.12: the bulletin of the 29th still stands — no December difference
        $this->rates('2025-01-31', 1.7711, 0.0175);
        $this->rates('2025-02-28', 1.7700, 0.0180);
        $this->inTenant($admin, function () {
            $rub = \App\Models\BankAccount::create(['name' => 'RUB', 'bank_name' => 'Bank', 'currency' => 'RUB', 'opening_balance' => 0, 'is_active' => true]);
            $eur = \App\Models\BankAccount::create(['name' => 'EUR', 'bank_name' => 'Bank', 'currency' => 'EUR', 'opening_balance' => 0, 'is_active' => true]);
            $tx = fn ($a, $dir, $kind, $date, $qty, $azn) => \App\Models\BankTransaction::create(['bank_account_id' => $a->id, 'direction' => $dir, 'kind' => $kind,
                'transaction_date' => $date, 'amount' => $qty, 'currency' => $a->currency, 'cbar_rate' => round($azn / $qty, 8), 'applied_rate' => round($azn / $qty, 8),
                'amount_azn' => $azn, 'cbar_amount_azn' => $azn, 'purpose' => 'x']);
            $tx($rub, 'in', 'regular', '2024-12-29', 9898036.68, 161516.16);
            $tx($rub, 'out', 'conversion', '2025-01-07', 9898036.68, 157992.46);
            $tx($rub, 'in', 'regular', '2025-01-27', 9729097.44, round(9729097.44 * 0.017382, 2));
            $tx($rub, 'out', 'conversion', '2025-01-28', 9729097.44, round(9729097.44 * 0.017487, 2));
            $tx($eur, 'in', 'conversion', '2025-01-27', 73416.93, round(73416.93 * 1.7786, 2));
            $tx($eur, 'out', 'regular', '2025-01-28', 73350.00, round(73350 * 1.7739, 2));
            $tx($rub, 'in', 'transfer', '2025-01-29', 100, 1.75);   // between own accounts: not a lot
        });

        $d = $this->inTenant($admin, fn () => (new CashflowReport)->build('2024-12', '2025-01'));
        $items = collect($d['other'][0]['details']['2025-01'])->keyBy('t');
        $this->assertSame(-3523.70, $items['RUB partiyası 29.12.2024 → konvertasiya']['v'], '9 898 036.68 × (0.0163181 − 0.015962): a loss');
        $this->assertSame(1021.56, $items['RUB partiyası 27.01.2025 → konvertasiya']['v'], '9 729 097.44 × (0.017382 − 0.017487): a gain');
        $this->assertSame(round(-73350 * (1.7786 - 1.7739), 2), $items['EUR partiyası 27.01.2025 → x']['v']);
        $this->assertSame(round(66.93 * 1.7711 - 66.93 * 1.7786, 2), $items['EUR qalığı — ay sonu']['v'], 'book − balance × the 31.01 rate');
        $this->assertSame(0.0, $d['oth']['2024-12']);
        $this->assertEqualsWithDelta(-3523.70 + 1021.56 + round(-73350 * 0.0047, 2) + round(66.93 * (1.7711 - 1.7786), 2), $d['oth']['2025-01'], 0.011, 'each posting rounded on its own');
        $this->assertSame([], $d['revalWarn']);
        // it moves the balance: net = in − out + other
        $this->assertEqualsWithDelta($d['in']['2025-01'] - $d['out']['2025-01'] + $d['oth']['2025-01'], $d['net']['2025-01'], 0.001);
        // Net Cash: each account at the month end, in AZN at that day's CBAR
        $cash = collect($d['cash']['accounts'])->keyBy('label');
        $this->assertSame(['2024-12' => 161516.16, '2025-01' => 1.75], $cash['RUB']['values'], '9 898 036.68 × 0.016318; 100 RUB × 0.0175');
        $this->assertSame(round(66.93 * 1.7711, 2), $cash['EUR']['values']['2025-01'], '66.93 EUR × 1.7711');
        $this->assertSame(round(1.75 + round(66.93 * 1.7711, 2), 2), $d['cash']['total']['2025-01']);
        $this->assertNull((new CashflowReport(999))->build('2024-12', '2025-01')['cash'] ?? null);

        // a project filter leaves it out — the accounts belong to the whole company
        $this->assertSame([], $this->inTenant($admin, fn () => (new CashflowReport(999))->build('2024-12', '2025-01'))['other']);

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-01']))->assertOk()
            ->assertSee('Digər əməliyyat gəliri (xərci)')->assertSee('Valyutanın yenidən qiymətləndirilməsi')->assertSee('('.num(abs($d['oth']['2025-01'])).')')
            ->assertSee('Net Cash (Xalis pul vəsaitləri) açıqlaması')->assertSee('Hesablarda cəmi')->assertSee(num(161516.16));
    }
}
