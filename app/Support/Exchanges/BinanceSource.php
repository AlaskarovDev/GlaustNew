<?php

namespace App\Support\Exchanges;

use Illuminate\Support\Facades\Cache;

/** Binance — the spot market's fiat pairs against USDT (a dollar stablecoin, ≈ 1 USD), the daily close. */
class BinanceSource extends RateSource
{
    private const BASE = 'https://api.binance.com/api/v3';

    private const FIAT = ['EUR', 'GBP', 'TRY', 'RUB', 'UAH', 'KZT', 'BRL', 'ARS', 'MXN', 'COP', 'ZAR', 'IDR', 'NGN', 'PLN', 'RON', 'CZK', 'JPY', 'AUD', 'CHF', 'AZN', 'GEL'];

    public function key(): string { return 'binance'; }

    public function name(): string { return 'Binance'; }

    public function kind(): string { return 'birja'; }

    public function country(): string { return __('Beynəlxalq (kripto birja)'); }

    public function site(): string { return 'https://www.binance.com'; }

    public function note(): string { return __('Spot bazarda USDT cütləri (USDT ≈ 1 USD), canlı qiymət; tarixçə — gündəlik bağlanış'); }

    /** @return array<string, array{0: string, 1: bool}> currency => [symbol, inverted (XXXUSDT: USD per unit)] */
    private function pairs(): array
    {
        return Cache::remember('exchanges:binance:pairs', now()->addDay(), function () {
            $symbols = $this->http()->get(self::BASE.'/exchangeInfo', ['permissions' => 'SPOT'])->throw()->json('symbols') ?? [];
            $out = [];
            foreach ($symbols as $s) {
                if (($s['status'] ?? '') !== 'TRADING') {
                    continue;
                }
                if ($s['baseAsset'] === 'USDT' && in_array($s['quoteAsset'], self::FIAT, true)) {
                    $out[$s['quoteAsset']] = [$s['symbol'], false];
                } elseif ($s['quoteAsset'] === 'USDT' && in_array($s['baseAsset'], self::FIAT, true)) {
                    $out[$s['baseAsset']] = [$s['symbol'], true];
                }
            }

            return $out;
        });
    }

    public function latest(): array
    {
        $pairs = $this->pairs();
        if (! $pairs) {
            return ['date' => null, 'rates' => []];
        }
        $prices = collect($this->http()->get(self::BASE.'/ticker/price', ['symbols' => json_encode(array_column($pairs, 0))])->throw()->json())
            ->pluck('price', 'symbol');
        $rates = [];
        foreach ($pairs as $cur => [$symbol, $inv]) {
            $p = (float) ($prices[$symbol] ?? 0);
            if ($p > 0) {
                $rates[$cur] = $inv ? 1 / $p : $p;
            }
        }

        return ['date' => $this->to()->toDateString(), 'rates' => $rates];
    }

    public function history(string $code, int $days): array
    {
        [$symbol, $inv] = $this->pairs()[$code] ?? [null, false];
        if (! $symbol) {
            return [];
        }
        $out = [];
        foreach ($this->http()->get(self::BASE.'/klines', ['symbol' => $symbol, 'interval' => '1d', 'limit' => $days])->throw()->json() ?? [] as $k) {
            $close = (float) $k[4];
            if ($close > 0) {
                $out[gmdate('Y-m-d', intdiv((int) $k[0], 1000))] = $inv ? 1 / $close : $close;
            }
        }

        return $out;
    }
}
