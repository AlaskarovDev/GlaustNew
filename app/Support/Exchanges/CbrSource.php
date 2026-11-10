<?php

namespace App\Support\Exchanges;

use Illuminate\Support\Facades\Cache;

/** The Bank of Russia's official rates (roubles per unit), crossed to the US dollar. */
class CbrSource extends RateSource
{
    private const BASE = 'https://www.cbr.ru/scripts';

    public function key(): string { return 'cbr'; }

    public function name(): string { return 'Rusiya Mərkəzi Bankı (CBR)'; }

    public function kind(): string { return 'bank'; }

    public function country(): string { return 'Rusiya'; }

    public function site(): string { return 'https://www.cbr.ru'; }

    public function note(): string { return __('Rəsmi kurslar (RUB-a nisbətən), USD üzrə çarpaz hesablanır'); }

    private function xml(string $path, array $query = []): \SimpleXMLElement
    {
        $body = $this->http()->accept('application/xml')->get(self::BASE.'/'.$path, $query)->throw()->body();
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            throw new \RuntimeException('CBR: XML oxunmadı');
        }

        return $xml;
    }

    private static function num(string $v): float
    {
        return (float) str_replace([',', ' '], ['.', ''], $v);
    }

    /** @return array{date: ?string, rub: array<string, float>, ids: array<string, string>} roubles per unit, the CBR ids */
    private function daily(): array
    {
        return Cache::remember('exchanges:cbr:daily', now()->addMinutes(30), function () {
            $x = $this->xml('XML_daily.asp');
            $rub = $ids = [];
            foreach ($x->Valute as $v) {
                $code = (string) $v->CharCode;
                $rub[$code] = self::num((string) $v->Value) / max(1, self::num((string) $v->Nominal));
                $ids[$code] = (string) $v['ID'];
            }
            $d = \DateTime::createFromFormat('d.m.Y', (string) $x['Date']);

            return ['date' => $d ? $d->format('Y-m-d') : null, 'rub' => $rub, 'ids' => $ids];
        });
    }

    public function latest(): array
    {
        $d = $this->daily();
        $usd = $d['rub']['USD'] ?? null;
        if (! $usd) {
            return ['date' => null, 'rates' => []];
        }
        $rates = ['RUB' => $usd];
        foreach ($d['rub'] as $c => $rub) {
            if ($c !== 'USD' && $rub > 0) {
                $rates[$c] = $usd / $rub;
            }
        }

        return ['date' => $d['date'], 'rates' => $rates];
    }

    public function history(string $code, int $days): array
    {
        $usd = $this->series('USD', $days);

        return $code === 'RUB' ? $usd : self::cross($usd, $this->series($code, $days));
    }

    private function series(string $code, int $days): array
    {
        $id = $this->daily()['ids'][$code] ?? null;
        if (! $id) {
            return [];
        }
        $x = $this->xml('XML_dynamic.asp', ['date_req1' => $this->from($days)->format('d/m/Y'), 'date_req2' => $this->to()->format('d/m/Y'), 'VAL_NM_RQ' => $id]);
        $out = [];
        foreach ($x->Record as $r) {
            $d = \DateTime::createFromFormat('d.m.Y', (string) $r['Date']);
            if ($d) {
                $out[$d->format('Y-m-d')] = self::num((string) $r->Value) / max(1, self::num((string) $r->Nominal));
            }
        }

        return $out;
    }
}
