<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Support\Reports\BalanceReport;
use App\Support\Reports\ProfitLossReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Mənfəət və zərər» and «Balans» as in the company's MZ and BALANS sheets — ATF row 3, plus salary and bank fees. */
class ProfitBalanceReportTest extends TestCase
{
    use Concerns\AtfRow3, RefreshDatabase;

    public function test_profit_and_loss_follows_the_mz_sheet(): void
    {
        [$admin] = $this->row3();
        $this->rates('2024-12-31', 1.7700, 0.016318);
        $this->rates('2025-01-31', 1.7659, 0.0175);
        $this->inTenant($admin, function () {
            CurrencyExchange::where('direction', 'sell')->update(['difference_azn' => 613.68]);
            CurrencyExchange::where('direction', 'buy')->update(['difference_azn' => 915.48]);
            $e = fn ($cat, $azn, $date = '2025-01-20') => Expense::create(['expense_date' => $date, 'category_id' => $cat->id, 'description' => 'x', 'amount' => $azn, 'currency' => 'AZN',
                'amount_azn' => $azn, 'status' => 'paid', 'paid_at' => $date, 'payment_method' => 'cash']);
            $e(Category::create(['scope' => 'expense', 'name' => 'Ölkədaxili bank komissiyonu', 'color' => '#64748b']), 30);
            $e(Category::create(['scope' => 'expense', 'name' => 'Əmək haqqı', 'color' => '#64748b']), 728);
            $e(Category::create(['scope' => 'expense', 'name' => 'DSMF', 'color' => '#64748b']), 208);
            $e(Category::create(['scope' => 'expense', 'name' => 'İcbari Tibbi Sığorta', 'color' => '#64748b']), 32);
            $e(Category::create(['scope' => 'expense', 'name' => 'B2B CONSULTING MMC', 'color' => '#64748b']), 1600);
            $e(Category::create(['scope' => 'expense', 'name' => 'Ofis', 'color' => '#64748b', 'pl_line' => '221.13']), 5);   // put on a line by hand
        });

        $d = $this->inTenant($admin, fn () => (new ProfitLossReport)->build('2025-01', '2025-01'));
        $l = fn ($code) => $d['lines'][$code]['values']['2025-01'];
        // the sale and its cost at CBAR of the act day (28.01), as the sheet's «test» page sums them
        $this->assertSame(round(9898036.68 * 0.017487, 2), $l('217.2'));
        $this->assertEqualsWithDelta(73644.80 * 1.7739 + 979732.68 * 0.017487, $l('232.1'), 0.02, 'purchase + logistics at the act day');
        // expenses on their lines
        $this->assertSame([728.0, 208.0, 32.0, 1600.0], [$l('221.1.2'), $l('221.2'), $l('230.2'), $l('232')]);
        $this->assertSame(round(613.68 + 915.48 + 30 + 5, 2), $l('221.13'), 'Kurs fərqi + the bank fee + the category put on 221.13');
        // 214 / 219.3: the 1C postings of January
        $this->assertGreaterThan(0, $l('214'));
        $this->assertLessThan(0, $l('219.3'));
        $this->assertSame(round($l('217.2') + $l('214') + $l('219.3'), 2), $d['in']['2025-01']);
        $this->assertSame(round($d['in']['2025-01'] - $d['out']['2025-01'], 2), $d['result']['2025-01']);
        $this->assertSame(round($d['result']['2025-01'] * 0.2, 2), $d['tax']['2025-01']);
        $this->assertNotEmpty($d['lines']['217.2']['items']['2025-01'], 'each figure carries what it is made of');

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['profit', 'from' => '2025-01', 'to' => '2025-01']))->assertOk()
            ->assertSee('HESABAT DÖVRÜNDƏ MƏNFƏƏT (ZƏRƏR)')->assertSee('217.2')->assertSee('AR hüdudlarından kənarda əldə edilmiş gəlirlər')->assertSee(num($l('217.2')));
        $this->assertStringContainsString('spreadsheetml', $this->get(route('analytics.show', ['profit', 'from' => '2025-01', 'to' => '2025-01', 'format' => 'xlsx']))->headers->get('content-type'));
        // the category page shows and keeps the line
        $this->get(route('expenses.categories'))->assertOk()->assertSee('221.1.2');
    }

    public function test_balance_follows_the_balans_sheet(): void
    {
        [$admin, $deal] = $this->row3();
        $this->rates('2024-12-31', 1.7700, 0.016318);
        $this->rates('2025-01-31', 1.7659, 0.0175);

        // 31.12.2024: the buyer has paid, the act is still to come — an advance received (64) at the month-end rate
        $dec = $this->inTenant($admin, fn () => app(BalanceReport::class)->build('2024-12-31'));
        $this->assertSame(round(9898036.68 * 0.016318, 2), $dec['liabilities']['64']['value']);
        $this->assertSame(round(9898036.68 * 0.016318, 2), $dec['assets']['52']['value'], 'the roubles on the account');
        $this->assertSame(0.0, $dec['gap'], 'the books close');

        // 31.01.2025: act done (28.01), seller paid, buyer paid; the logistics invoice (28.01) is paid only on 10.02 — a payable (60)
        $jan = $this->inTenant($admin, fn () => app(BalanceReport::class)->build('2025-01-31'));
        $this->assertSame(0.0, $jan['liabilities']['64']['value']);
        $this->assertSame(round(979732.68 * 0.0175, 2), $jan['liabilities']['60']['value']);
        $this->assertSame('Trade TR-1 · logistikaya borc', $jan['liabilities']['60']['items'][0]['t']);
        $pl = $this->inTenant($admin, fn () => array_sum((new ProfitLossReport)->build('2025-01', '2025-01')['result']));
        $this->assertEqualsWithDelta($pl, $jan['liabilities']['80']['value'], 0.001, '80 is the year\'s result to the day');
        $this->assertSame(round($jan['assetsTotal'] - $jan['liabilitiesTotal'], 2), $jan['gap']);

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['balance', 'month' => '2025-01']))->assertOk()
            ->assertSee('AKTİVLƏR')->assertSee('Alınmış avanslar üzrə hesablaşmalar')->assertSee('Fərq (aktivlər − passivlər)');
        $this->assertStringContainsString('spreadsheetml', $this->get(route('analytics.show', ['balance', 'month' => '2025-01', 'format' => 'xlsx']))->headers->get('content-type'));
    }
}
