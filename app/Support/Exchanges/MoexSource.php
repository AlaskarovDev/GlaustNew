<?php

namespace App\Support\Exchanges;

/** Moscow Exchange — its indicative rates against the rouble (fixed twice a day; the evening, «mc», is the day's). */
class MoexSource extends RateSource
{
    private const BASE = 'https://iss.moex.com/iss/statistics/engines/futures/markets/indicativerates/securities';

    public function key(): string { return 'moex'; }

    public function name(): string { return 'Moskva Birjası (MOEX)'; }

    public function kind(): string { return 'birja'; }

    public function country(): string { return 'Rusiya'; }

    public function site(): string { return 'https://www.moex.com'; }

    public function note(): string { return __('Birjanın indikativ kursları (RUB-a nisbətən, axşam klirinqi), USD üzrə çarpaz hesablanır'); }

    public function latest(): array
    {
        $rows = $this->http()->get(self::BASE.'.json', ['iss.meta' => 'off'])->throw()->json('securities.data') ?? [];
        $last = $date = [];
        foreach ($rows as [$day, , $secid, $rate]) {
            [$cur] = explode('/', $secid);
            $last[$cur] = (float) $rate;   // the later fixing of the day comes last
            $date[$cur] = $day;
        }
        $usd = $last['USD'] ?? null;
        if (! $usd) {
            return ['date' => null, 'rates' => []];
        }
        $rates = ['RUB' => $usd];
        foreach ($last as $cur => $rub) {
            if ($cur !== 'USD' && $rub > 0) {
                $rates[$cur] = $usd / $rub;
            }
        }

        return ['date' => $date['USD'] ?? null, 'rates' => $rates];
    }

    public function history(string $code, int $days): array
    {
        $usd = $this->series('USD', $days);

        return $code === 'RUB' ? $usd : self::cross($usd, $this->series($code, $days));
    }

    /** @return array<string, float> Y-m-d => roubles per unit (the evening fixing) */
    private function series(string $cur, int $days): array
    {
        $out = [];
        for ($start = 0; $start < 2000; $start += 100) {
            $rows = $this->http()->get(self::BASE."/{$cur}/RUB.json", ['iss.meta' => 'off', 'from' => $this->from($days)->toDateString(),
                'till' => $this->to()->toDateString(), 'start' => $start])->throw()->json('securities.data') ?? [];
            foreach ($rows as [$day, , , $rate]) {
                $out[$day] = (float) $rate;
            }
            if (count($rows) < 100) {
                break;
            }
        }
        ksort($out);

        return $out;
    }
}
