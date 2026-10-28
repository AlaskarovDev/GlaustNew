<?php

namespace Tests\Feature;

use App\Models\CurrencyRate;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * cbar.az unreachable from the server: the same day's CBAR rate as the ATF workbook recorded it stands in until the
 * bulletin itself comes — never another day's rate; bulletins fetched elsewhere come in through glaust:cbar-store.
 */
class CbarFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workbook_rate_of_the_same_day_stands_in_until_cbar_answers(): void
    {
        $down = true;   // cbar.az refuses until we say so
        $xml = file_get_contents(base_path('tests/Fixtures/cbar-sample.xml'));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$down, $xml) {
            preg_match('#currencies/(\d\d\.\d\d\.\d{4})\.xml#', $request->url(), $m);

            return $down ? \Illuminate\Support\Facades\Http::response('', 503)
                : \Illuminate\Support\Facades\Http::response(preg_replace('/Date="[^"]+"/', 'Date="'.$m[1].'"', $xml, 1), 200, ['Content-Type' => 'application/xml']);
        });
        $rates = app(CurrencyRates::class);

        $this->assertTrue($rates->seed('EUR', '2025-03-11', 1.8325, 'ATF Excel'));
        $this->assertFalse($rates->seed('EUR', '2025-03-11', 9.99, 'ATF Excel'), 'a stored rate is never overwritten by a workbook');
        $this->assertSame(1.8325, $rates->rate('EUR', '2025-03-11'), 'CBAR fails: the workbook\'s rate of that day');
        $this->assertNull($rates->tryRate('USD', '2025-03-11'), 'nothing for a currency the workbook did not give');
        try {
            $rates->rate('EUR', '2025-03-12');
            $this->fail('another day must not borrow the rate');
        } catch (RateUnavailable) {
        }

        // CBAR answers again: its bulletin replaces the workbook's figure
        Cache::flush();
        $down = false;
        $real = $rates->rate('EUR', '2025-03-11');
        $this->assertNotSame(1.8325, $real);
        $this->assertSame('CBAR', CurrencyRate::where('currency_code', 'EUR')->where('rate_date', '2025-03-11')->value('source'));
        $this->assertNotNull($rates->tryRate('USD', '2025-03-11'));
    }

    public function test_bulletins_fetched_elsewhere_are_stored(): void
    {
        $this->fakeCbar(['13.03.2025']);
        app(CurrencyRates::class)->seed('EUR', '2025-03-13', 1.80, 'ATF Excel');
        $payload = base64_encode(gzencode(json_encode(['2025-03-13' => ['bulletin' => '2025-03-13', 'rates' => [
            'EUR' => ['rate' => 1.8461, 'nominal' => 1, 'value' => 1.8461, 'name' => '1 Avro'],
            'RUB' => ['rate' => 0.019, 'nominal' => 100, 'value' => 1.9, 'name' => '100 Rusiya rublu'],
            'bad' => ['rate' => 1],
        ]], 'nonsense' => ['rates' => ['USD' => ['rate' => 1.7]]]])));
        $this->artisan('glaust:cbar-store', ['payload' => $payload])->expectsOutputToContain('1 gün yazıldı.')->assertSuccessful();
        $this->assertSame(1.8461, app(CurrencyRates::class)->rate('EUR', '2025-03-13'), 'the bulletin replaced the workbook\'s figure');
        $this->assertSame('CBAR', CurrencyRate::where('currency_code', 'EUR')->where('rate_date', '2025-03-13')->value('source'));
        $this->artisan('glaust:cbar-store', ['payload' => 'not-base64'])->assertFailed();
    }
}
