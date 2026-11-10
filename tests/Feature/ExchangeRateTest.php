<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** «Birja»: rates against the US dollar from a market or a central bank the user picks, with a 30 / 90-day chart. */
class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMarkets(bool $moexDown = false): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake([
            'iss.moex.com/*/securities/USD/RUB.json*' => Http::response(['securities' => ['data' => [
                ['2026-11-05', '19:00:00', 'USD/RUB', 80.0, 'mc'], ['2026-11-06', '19:00:00', 'USD/RUB', 82.0, 'mc']]]]),
            'iss.moex.com/*/securities/EUR/RUB.json*' => Http::response(['securities' => ['data' => [
                ['2026-11-05', '19:00:00', 'EUR/RUB', 88.0, 'mc'], ['2026-11-06', '19:00:00', 'EUR/RUB', 92.0, 'mc']]]]),
            'iss.moex.com/*' => $moexDown ? Http::response('', 503) : Http::response(['securities' => ['data' => [
                ['2026-11-09', '14:00:00', 'EUR/RUB', 92.0, 'tc'], ['2026-11-09', '19:00:00', 'EUR/RUB', 92.4, 'mc'],
                ['2026-11-09', '14:00:00', 'USD/RUB', 83.0, 'tc'], ['2026-11-09', '19:00:00', 'USD/RUB', 83.16, 'mc'],
                ['2026-11-09', '19:00:00', 'CNY/RUB', 12.0, 'mc']]]]),
            'api.binance.com/api/v3/exchangeInfo*' => Http::response(['symbols' => [
                ['symbol' => 'USDTTRY', 'status' => 'TRADING', 'baseAsset' => 'USDT', 'quoteAsset' => 'TRY'],
                ['symbol' => 'EURUSDT', 'status' => 'TRADING', 'baseAsset' => 'EUR', 'quoteAsset' => 'USDT'],
                ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'baseAsset' => 'BTC', 'quoteAsset' => 'USDT']]]),
            'api.binance.com/api/v3/ticker/price*' => Http::response([['symbol' => 'USDTTRY', 'price' => '50.0'], ['symbol' => 'EURUSDT', 'price' => '1.25']]),
            'api.binance.com/api/v3/klines*' => Http::response([[1794182400000, '1', '1', '1', '1.25'], [1794268800000, '1', '1', '1', '1.0']]),
        ]);
    }

    public function test_rates_against_the_dollar_and_the_chart(): void
    {
        $this->fakeMarkets();
        $admin = $this->makeCompany();
        $this->actingAs($admin);

        // MOEX: the evening fixing, crossed to the dollar — 1 USD = 83.16 RUB, 1 USD = 83.16 / 92.4 EUR
        $page = $this->get(route('exchanges.index', ['source' => 'moex', 'code' => 'EUR', 'days' => 90]))->assertOk()
            ->assertSee('Moskva Birjası (MOEX)')->assertSee('Binance')->assertSee('Avropa Mərkəzi Bankı (ECB)')
            ->assertSee(route('exchanges.index'), false)->assertSee('83,16')->assertSee(rate_fmt(round(83.16 / 92.4, 4)))->assertSee('1 USD neçə EUR edir')->assertSee('Bugünkü kurs')->assertSee('06.11 tarixinə görə');
        $chart = json_decode(html_entity_decode((string) preg_replace('/.*x-chart="([^"]+)".*/s', '$1', $page->getContent())), true);
        $this->assertSame([round(80 / 88, 6), round(82 / 92, 6)], $chart['series'][0]['data'], 'USD/EUR per day = USD/RUB ÷ EUR/RUB');
        $this->assertSame(['05.11', '06.11'], $chart['categories']);

        // Binance: USDTTRY is TRY per dollar, EURUSDT turned round; the daily close
        $page = $this->get(route('exchanges.index', ['source' => 'binance', 'code' => 'EUR']))->assertOk()->assertSee('50,0000')->assertSee('0,8000');
        $chart = json_decode(html_entity_decode((string) preg_replace('/.*x-chart="([^"]+)".*/s', '$1', $page->getContent())), true);
        $this->assertEquals([0.8, 1.0], $chart['series'][0]['data']);
    }

    public function test_a_market_that_does_not_answer(): void
    {
        $this->fakeMarkets(moexDown: true);
        $this->actingAs($this->makeCompany())->get(route('exchanges.index', ['source' => 'moex']))->assertOk()->assertSee('hazırda cavab vermir');
    }
}
