<?php

namespace App\Support\Exchanges;

/** The National Bank of Ukraine's official rates (hryvnias per unit), crossed to the US dollar. */
class NbuSource extends RateSource
{
    public function key(): string { return 'nbu'; }

    public function name(): string { return 'Ukrayna Milli Bankı (NBU)'; }

    public function kind(): string { return 'bank'; }

    public function country(): string { return 'Ukrayna'; }

    public function site(): string { return 'https://bank.gov.ua'; }

    public function note(): string { return __('Rəsmi kurslar (UAH-a nisbətən), USD üzrə çarpaz hesablanır'); }

    public function latest(): array
    {
        $rows = $this->http()->get('https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange', ['json' => ''])->throw()->json() ?? [];
        $uah = collect($rows)->mapWithKeys(fn ($r) => [$r['cc'] => (float) $r['rate']])->all();
        $usd = $uah['USD'] ?? null;
        if (! $usd) {
            return ['date' => null, 'rates' => []];
        }
        $rates = ['UAH' => $usd];
        foreach ($uah as $c => $v) {
            if ($c !== 'USD' && $v > 0 && preg_match('/^[A-Z]{3}$/', $c) && ! in_array($c, ['XAU', 'XAG', 'XPT', 'XPD', 'XDR'], true)) {
                $rates[$c] = $usd / $v;
            }
        }
        $d = \DateTime::createFromFormat('d.m.Y', (string) ($rows[0]['exchangedate'] ?? ''));

        return ['date' => $d ? $d->format('Y-m-d') : null, 'rates' => $rates];
    }

    public function history(string $code, int $days): array
    {
        $usd = $this->series('USD', $days);

        return $code === 'UAH' ? $usd : self::cross($usd, $this->series($code, $days));
    }

    private function series(string $code, int $days): array
    {
        $rows = $this->http()->get('https://bank.gov.ua/NBU_Exchange/exchange_site', ['start' => $this->from($days)->format('Ymd'), 'end' => $this->to()->format('Ymd'),
            'valcode' => strtolower($code), 'sort' => 'exchangedate', 'order' => 'asc', 'json' => ''])->throw()->json() ?? [];
        $out = [];
        foreach ($rows as $r) {
            $d = \DateTime::createFromFormat('d.m.Y', (string) $r['exchangedate']);
            if ($d) {
                $out[$d->format('Y-m-d')] = (float) $r['rate_per_unit'];
            }
        }

        return $out;
    }
}
