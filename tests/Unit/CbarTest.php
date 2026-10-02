<?php

namespace Tests\Unit;

use App\Models\CurrencyRate;
use App\Services\Cbar\CbarClient;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CbarTest extends TestCase
{
    use RefreshDatabase;

    private function xml(): string
    {
        return file_get_contents(base_path('tests/Fixtures/cbar-sample.xml'));
    }

    public function test_parses_real_bulletin_per_one_unit(): void
    {
        $rates = app(CbarClient::class)->parse($this->xml());

        $this->assertArrayHasKey('USD', $rates);
        $this->assertArrayHasKey('EUR', $rates);
        $this->assertSame(1.0, $rates['USD']['nominal']);

        // RUB is quoted per 100: the per-unit rate must be Value / 100, with 6+ decimals kept.
        $this->assertSame(100.0, $rates['RUB']['nominal']);
        $this->assertEqualsWithDelta($rates['RUB']['value'] / 100, $rates['RUB']['rate'], 1e-9);
        $this->assertLessThan(0.1, $rates['RUB']['rate']);

        // Bank metals "1 t.u." parse as nominal 1.
        $this->assertSame(1.0, $rates['XAU']['nominal']);
    }

    public function test_reads_bulletin_date(): void
    {
        $this->assertSame('2026-10-02', app(CbarClient::class)->bulletinDate($this->xml()));
    }

    public function test_garbage_xml_gives_no_rates(): void
    {
        $this->assertSame([], app(CbarClient::class)->parse('<html>not xml'));
    }

    public function test_rates_are_fetched_once_cached_and_stored_with_eight_decimals(): void
    {
        $hits = $this->fakeCbar();
        $svc = app(CurrencyRates::class);
        $day = CarbonImmutable::today()->subDays(10);

        $rub = $svc->rate('RUB', $day);
        $svc->rate('USD', $day);
        $svc->rate('EUR', $day->format('d.m.Y'));

        $this->assertSame(1, $hits[$day->format('d.m.Y')], 'one HTTP call per date');
        $stored = CurrencyRate::where('currency_code', 'RUB')->where('rate_date', $day->toDateString())->first();
        $this->assertNotNull($stored);
        $this->assertSame(round($rub, 8), round((float) $stored->rate, 8), 'no 4-decimal truncation');
        $this->assertSame($day->format('Y-m-d'), $stored->bulletin_date->format('Y-m-d'));
    }

    public function test_azn_is_always_one_without_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->assertSame(1.0, app(CurrencyRates::class)->rate('AZN', '2026-01-01'));
        Http::assertNothingSent();
    }

    public function test_future_date_never_reaches_cbar(): void
    {
        $hits = $this->fakeCbar();
        $this->expectException(RateUnavailable::class);
        try {
            app(CurrencyRates::class)->rate('USD', CarbonImmutable::today('Asia/Baku')->addDay());
        } finally {
            $this->assertCount(0, $hits);
        }
    }

    public function test_unreadable_date_never_reaches_cbar(): void
    {
        $hits = $this->fakeCbar();
        $this->assertNull(app(CurrencyRates::class)->tryRate('USD', 'not-a-date'));
        $this->assertNull(app(CurrencyRates::class)->tryRate('USD', '31.02.2026'));
        $this->assertCount(0, $hits);
    }

    public function test_missing_rate_is_an_error_not_a_fallback(): void
    {
        $day = CarbonImmutable::today()->subDays(3);
        $this->fakeCbar([$day->format('d.m.Y')]);
        // A stored rate for ANOTHER day must not be used as a substitute.
        CurrencyRate::create(['currency_code' => 'USD', 'rate_date' => $day->subDay()->toDateString(), 'rate' => 1.7, 'nominal' => 1, 'value' => 1.7]);

        $this->expectException(RateUnavailable::class);
        app(CurrencyRates::class)->rate('USD', $day);
    }

    public function test_failure_is_remembered_briefly_so_pages_do_not_wait_on_cbar(): void
    {
        $day = CarbonImmutable::today()->subDays(2);
        $hits = $this->fakeCbar([$day->format('d.m.Y')]);
        $svc = app(CurrencyRates::class);
        $this->assertNull($svc->tryRate('USD', $day));
        $this->assertNull($svc->tryRate('USD', $day));
        $this->assertSame(1, $hits[$day->format('d.m.Y')]);
    }

    public function test_unknown_currency_is_an_error(): void
    {
        $this->fakeCbar();
        $this->expectException(RateUnavailable::class);
        app(CurrencyRates::class)->rate('ZZZ', CarbonImmutable::today()->subDays(5));
    }

    public function test_ticker_reports_bulletin_date_and_trend(): void
    {
        $this->fakeCbar();
        $t = app(CurrencyRates::class)->ticker(['USD', 'EUR', 'RUB']);

        $this->assertTrue($t['ok']);
        $this->assertFalse($t['stale']);
        $this->assertCount(3, $t['items']);
        $this->assertSame('USD', $t['items'][0]['code']);
        $this->assertContains($t['items'][0]['trend'], ['up', 'down', 'flat']);
    }
}
