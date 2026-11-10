<?php

namespace App\Support\Exchanges;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** The markets and central banks of the «Birja» page, and their rates against the US dollar, cached. */
class Exchanges
{
    /** @return array<string, RateSource> */
    public static function all(): array
    {
        $list = [new MoexSource, new BinanceSource, new EcbSource, new CbrSource, new NbuSource, new CbarSource];

        return collect($list)->keyBy(fn (RateSource $s) => $s->key())->all();
    }

    public static function get(?string $key): RateSource
    {
        $all = self::all();

        return $all[$key] ?? reset($all);
    }

    /** @return array{date: ?string, rates: array<string, float>, error: ?string} */
    public static function latest(RateSource $s): array
    {
        $down = "exchanges:{$s->key()}:down";
        try {
            if (Cache::has($down)) {
                throw new \RuntimeException('down');   // it did not answer a moment ago: do not keep the page waiting
            }

            return Cache::remember("exchanges:{$s->key()}:latest", now()->addMinutes($s->key() === 'binance' ? 2 : 15), fn () => $s->latest()) + ['error' => null];
        } catch (\Throwable $e) {
            $e->getMessage() === 'down' || Cache::put($down, true, now()->addMinutes(10));
            Log::warning("Birja {$s->key()}: ".$e->getMessage());

            return ['date' => null, 'rates' => [], 'error' => __(':v1 hazırda cavab vermir. Bir azdan yenidən cəhd edin və ya başqa mənbə seçin.', ['v1' => $s->name()])];
        }
    }

    /** @return array{0: array<string, float>, 1: ?string} the history and an error */
    public static function history(RateSource $s, string $code, int $days): array
    {
        try {
            return [Cache::remember("exchanges:{$s->key()}:{$code}:{$days}", now()->addMinutes(60), fn () => $s->history($code, $days)), null];
        } catch (\Throwable $e) {
            Log::warning("Birja {$s->key()} {$code}: ".$e->getMessage());

            return [[], __('Tarixçə hazırda alınmadı.')];
        }
    }
}
