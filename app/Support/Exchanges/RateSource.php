<?php

namespace App\Support\Exchanges;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * A market or a central bank that publishes exchange rates. Every rate is given against the US dollar: how many units
 * of the currency one USD buys (RUB 83.2, EUR 0.89), oldest first in a history.
 */
abstract class RateSource
{
    abstract public function key(): string;

    abstract public function name(): string;

    /** 'birja' (a market) or 'bank' (a central bank's official rates). */
    abstract public function kind(): string;

    abstract public function country(): string;

    abstract public function site(): string;

    /** What its rates are: the instrument and the time of day. */
    abstract public function note(): string;

    /** @return array{date: ?string, rates: array<string, float>} units per 1 USD */
    abstract public function latest(): array;

    /** @return array<string, float> Y-m-d => units per 1 USD, oldest first */
    abstract public function history(string $code, int $days): array;

    protected function http(): PendingRequest
    {
        return Http::timeout(15)->retry(1, 300, throw: false)->withHeaders(['User-Agent' => 'TradeFlow/1.0 (+rates)'])->acceptJson();
    }

    protected function from(int $days): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now('Asia/Baku')->startOfDay()->subDays($days - 1);
    }

    protected function to(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now('Asia/Baku')->startOfDay();
    }

    /** a ÷ b per date, on the dates both have (crosses through a third currency). */
    protected static function cross(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $d => $v) {
            if (isset($b[$d]) && $b[$d] > 0) {
                $out[$d] = $v / $b[$d];
            }
        }
        ksort($out);

        return $out;
    }
}
