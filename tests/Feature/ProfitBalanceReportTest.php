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
        [$admin, $deal] = $this->row3();
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
        // 232.1 split: the purchase, the logistics, the rest — adding up to the line
        $sub = fn ($c) => $d['subs']['232.1'][$c]['values']['2025-01'];
        $this->assertEqualsWithDelta(73644.80 * 1.7739, $sub('232.1.001'), 0.02);
        $this->assertEqualsWithDelta(979732.68 * 0.017487, $sub('232.1.002'), 0.02);
        $this->assertSame(0.0, $sub('232.1.003'));
        $this->assertSame($l('232.1'), round($sub('232.1.001') + $sub('232.1.002') + $sub('232.1.003'), 2));
        // expenses on their lines
        $this->assertSame([728.0, 208.0, 32.0, 1600.0], [$l('221.1.2'), $l('221.2'), $l('230.2'), $l('232')]);
        $this->assertSame(round(613.68 + 915.48 + 30 + 5, 2), $l('221.13'), 'Kurs fərqi + the bank fee + the category put on 221.13');
        // 214 / 219.3: the 1C postings of January
        $this->assertGreaterThan(0, $l('214'));
        $this->assertLessThan(0, $l('219.3'));
        $this->assertSame(round($l('217.2') + $l('214') + $l('219.3'), 2), $d['in']['2025-01']);
        $this->assertSame(round($d['in']['2025-01'] - $d['out']['2025-01'], 2), $d['result']['2025-01']);
        $this->assertSame(round($d['result']['2025-01'] * 0.2, 2), $d['tax']['2025-01']);
        $this->assertSame(round(array_sum($d['result']) * 0.2, 2), $d['taxTotal'], 'the period\'s tax from its result');
        // the sheet goes on: net = profit − MV 20 %, dividend 5 % of it, what is left
        $this->assertSame(round(array_sum($d['result']) - $d['taxTotal'], 2), $d['netTotal']);
        $this->assertSame(round($d['netTotal'] * 0.05, 2), $d['dividendTotal']);
        $this->assertSame(round($d['netTotal'] - $d['dividendTotal'], 2), $d['retainedTotal']);
        $this->assertNotEmpty($d['lines']['217.2']['items']['2025-01'], 'each figure carries what it is made of');
        // a Trade / project filter: its sale and costs, not the company's own expenses
        $f = $this->inTenant($admin, fn () => (new ProfitLossReport)->build('2025-01', '2025-01', null, $deal->id));
        $this->assertSame($l('217.2'), $f['lines']['217.2']['values']['2025-01']);
        $this->assertSame(0.0, $f['lines']['221.1.2']['total'], 'the salary is not the Trade\'s');
        $none = $this->inTenant($admin, fn () => (new ProfitLossReport)->build('2025-01', '2025-01', 999999));
        $this->assertSame(0.0, $none['lines']['217.2']['total']);

        $this->actingAs($admin);
        $this->get(route('analytics.show', ['profit', 'from' => '2025-01', 'to' => '2025-01']))->assertOk()
            ->assertSee('HESABAT DÖVRÜNDƏ MƏNFƏƏT (ZƏRƏR)')->assertSee('Dividend 5%')->assertSee('Tam xalis mənfəət')->assertSee('232.1.002')->assertSee('217.2')->assertSee('AR hüdudlarından kənarda əldə edilmiş gəlirlər')->assertSee(num($l('217.2')));
        $this->assertStringContainsString('spreadsheetml', $this->get(route('analytics.show', ['profit', 'from' => '2025-01', 'to' => '2025-01', 'format' => 'xlsx']))->headers->get('content-type'));
        $this->get(route('analytics.show', ['profit', 'from' => '2025-01', 'to' => '2025-01', 'deal_id' => $deal->id]))->assertOk()->assertSee(num($l('217.2')));
        $this->get(route('analytics.show', ['cashflow', 'from' => '2024-12', 'to' => '2025-02', 'deal_id' => $deal->id]))->assertOk();
        $this->get(route('analytics.show', ['projects', 'from' => '2025-01', 'to' => '2025-01']))->assertOk()->assertSee('TR-1')
            ->assertSee(num(round(9898036.68 * 0.015962, 2)));   // as the Təsisçi hesabatı: the sale at CBAR of the day the rouble was sold (AC), not of the act
        $this->get(route('analytics.show', ['fx', 'from' => '2025-01', 'to' => '2025-02']))->assertOk();
        // «Proqnozla göstər»: the profit at the invoice's forecast rates beside the fact, and the difference
        $pr = $this->inTenant($admin, fn () => (new \App\Support\Reports\ProjectReport)->build('2025-01-01', '2025-01-31', null, true));
        $tr = $pr['projects'][0]['trades'][0];
        $this->assertNotNull($tr['fc'], 'the invoice has a forecast (1.79 / 0.0165)');
        $this->assertSame(round($tr['profit'] - $tr['fc'], 2), $tr['diff']);
        $this->assertSame($tr['diff'], $pr['total']['diff']);
        // the forecast opens line by line beside the fact: each column adds up to its profit
        $fw = $tr['fcWhy'];
        $this->assertSame('Proqnoz', $fw['a']);
        $this->assertEqualsWithDelta($tr['fc'], array_sum(array_column($fw['rows'], 'v')), 0.02);
        $this->assertEqualsWithDelta($tr['profit'], array_sum(array_column($fw['rows'], 'f')), 0.02);
        $this->get(route('analytics.show', ['projects', 'from' => '2025-01', 'to' => '2025-01', 'forecast' => 1]))->assertOk()->assertSee('Proqnoz mənfəət')->assertSee('Proqnozu gizlət')
            ->assertSee('Proqnoz üzrə Excel');
        // «Proqnoz üzrə Excel»: the same window for every Trade — amount, currency, forecast rate, the real one
        $sale = collect($fw['rows'])->firstWhere('kind', 'sale');
        $this->assertSame([9898036.68, 'RUB', 0.0165], [$sale['x']['n'], $sale['x']['cur'], $sale['x']['rq']]);
        $xlsx = $this->get(route('analytics.show', ['projects', 'from' => '2025-01', 'to' => '2025-01', 'format' => 'xlsx-forecast']));
        $xlsx->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'fc').'.xlsx';
        file_put_contents($path, $xlsx->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        // one row a Trade: the sale 9 898 036.68 RUB at 0.0165, the purchase in EUR at 1.79, the forecast profit and the fact
        $head = array_search('Trade', $sheet[3], true);
        $line = collect($sheet)->first(fn ($r) => ($r[$head + 1] ?? null) === $tr['code']);
        $this->assertSame([9898036.68, 'RUB', 0.0165, round($sale['v'], 2)], [(float) $line[5], $line[6], (float) $line[7], (float) $line[8]]);
        $this->assertSame(['EUR', 1.79], [$line[10], (float) $line[11]]);
        $this->assertEqualsWithDelta($tr['fc'], (float) $line[array_search('Proqnoz mənfəət', $sheet[3], true)], 0.01);
        $this->assertEqualsWithDelta($tr['profit'], (float) $line[array_search('Fakt mənfəət', $sheet[3], true)], 0.01);
        // «Əməliyyat tarixinə»: the profit on the seller's payment day, before the act — AC − AA − AJ − AL − AM − the logistics forecast
        $op = $this->inTenant($admin, fn () => (new \App\Support\Reports\ProjectReport)->build('2025-01-01', '2025-01-31', null, false, true));
        $ot = $op['projects'][0]['trades'][0];
        $this->assertNotNull($ot['op']);
        $this->assertSame('2025-01-07', $ot['opDay']);
        $this->assertSame(round($ot['profit'] - $ot['op'], 2), $ot['opDiff']);
        $this->assertNull($ot['fc'], 'the forecast is not asked for');
        // its make-up, line by line beside the fact: the lines add up to each, the fact is the Trade's XALİS MƏNFƏƏT
        $rows = $ot['opWhy']['rows'];
        $this->assertEqualsWithDelta($ot['op'], array_sum(array_column($rows, 'v')), 0.02);
        $this->assertEqualsWithDelta($ot['profit'], array_sum(array_column($rows, 'f')), 0.02);
        $this->assertSame($ot['profit'], $ot['opWhy']['f']);
        // «Kurs fərqi çıxılmaqla»: the bank's exchange result taken off the fact and the operation day alike; how it arose, line by line
        $nx = $this->inTenant($admin, fn () => (new \App\Support\Reports\ProjectReport)->build('2025-01-01', '2025-01-31', null, false, true, true));
        $nt = $nx['projects'][0]['trades'][0];
        $this->assertEqualsWithDelta($ot['profit'] - $ot['fx'], $nt['profit'], 0.01);
        $this->assertEqualsWithDelta($ot['op'] - $ot['fx'], $nt['op'], 0.01);
        $this->assertSame($ot['opDiff'], $nt['opDiff'], 'the same taken off both: the difference stays');
        $this->assertEqualsWithDelta(-$ot['fx'], array_sum(array_column($ot['fxWhy']['rows'], 'v')), 0.02);
        $this->get(route('analytics.show', ['projects', 'from' => '2025-01', 'to' => '2025-01', 'op' => 1, 'fx' => 1]))->assertOk()->assertSee('Kurs fərqi çıxılmadan göstər')->assertSee('kurs fərqi çıxılmaqla');
        $this->get(route('analytics.show', ['projects', 'from' => '2025-01', 'to' => '2025-01', 'op' => 1, 'forecast' => 1]))->assertOk()
            ->assertSee('Əməliyyat tarixinə mənfəət')->assertSee('Əməliyyat tarixini gizlət')->assertSee('Proqnozu gizlət');
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
