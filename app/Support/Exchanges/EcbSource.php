<?php

namespace App\Support\Exchanges;

/** The European Central Bank's reference rates (published about 16:00 CET on working days), through the Frankfurter API. */
class EcbSource extends RateSource
{
    private const BASE = 'https://api.frankfurter.dev/v1';

    public function key(): string { return 'ecb'; }

    public function name(): string { return 'Avropa Mərkəzi Bankı (ECB)'; }

    public function kind(): string { return 'bank'; }

    public function country(): string { return __('Avropa İttifaqı'); }

    public function site(): string { return 'https://www.ecb.europa.eu'; }

    public function note(): string { return __('Rəsmi istinad kursları, iş günləri saat 16:00 (CET)'); }

    public function latest(): array
    {
        $j = $this->http()->get(self::BASE.'/latest', ['base' => 'USD'])->throw()->json();

        return ['date' => $j['date'] ?? null, 'rates' => array_map('floatval', $j['rates'] ?? [])];
    }

    public function history(string $code, int $days): array
    {
        $j = $this->http()->get(self::BASE.'/'.$this->from($days)->toDateString().'..'.$this->to()->toDateString(), ['base' => 'USD', 'symbols' => $code])->throw()->json();
        $out = [];
        foreach ($j['rates'] ?? [] as $d => $r) {
            if (isset($r[$code])) {
                $out[$d] = (float) $r[$code];
            }
        }
        ksort($out);

        return $out;
    }
}
