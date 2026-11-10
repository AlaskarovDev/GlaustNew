<?php

namespace App\Support\Exchanges;

use App\Services\Cbar\CurrencyRates;

/** The Central Bank of Azerbaijan, from the system's own stored bulletins (manats per unit), crossed to the US dollar. */
class CbarSource extends RateSource
{
    public function key(): string { return 'cbar'; }

    public function name(): string { return 'Azərbaycan Mərkəzi Bankı (CBAR)'; }

    public function kind(): string { return 'bank'; }

    public function country(): string { return 'Azərbaycan'; }

    public function site(): string { return 'https://www.cbar.az'; }

    public function note(): string { return __('Rəsmi kurslar (AZN-ə nisbətən), USD üzrə çarpaz hesablanır'); }

    public function latest(): array
    {
        $rates = app(CurrencyRates::class);
        $snap = $rates->snapshot($rates->today());
        $azn = array_map(fn ($r) => (float) $r['rate'], $snap['rates']);
        $usd = $azn['USD'] ?? null;
        if (! $usd) {
            return ['date' => null, 'rates' => []];
        }
        $out = ['AZN' => $usd];
        foreach ($azn as $c => $v) {
            if ($c !== 'USD' && $v > 0 && ! in_array($c, ['XAU', 'XAG', 'XPT', 'XPD', 'SDR', 'XDR'], true)) {
                $out[$c] = $usd / $v;
            }
        }

        return ['date' => $snap['bulletin'], 'rates' => $out];
    }

    public function history(string $code, int $days): array
    {
        $rates = app(CurrencyRates::class);
        $usd = $rates->history('USD', $days);

        return $code === 'AZN' ? $usd : self::cross($usd, $rates->history($code, $days));
    }
}
