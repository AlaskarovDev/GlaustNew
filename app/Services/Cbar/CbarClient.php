<?php

namespace App\Services\Cbar;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the Central Bank of Azerbaijan: https://cbar.az/currencies/dd.mm.yyyy.xml
 * Port of the old Glaust App\Libraries\Cbar_rates (fetch + parse_xml).
 *
 * Observed behaviour (checked against cbar.az): any date — a weekend, today before
 * publication, even a FUTURE date — returns HTTP 200 with the latest published
 * bulletin, whose own date is in ValCurs@Date. So the bulletin date is kept, and
 * callers must never ask for a future date.
 */
class CbarClient
{
    /** @return array{date: ?string, rates: array<string, array{rate: float, nominal: float, value: float, name: string}>} */
    public function fetch(\DateTimeInterface $date): array
    {
        $url = sprintf(config('glaust.cbar.url'), $date->format('d.m.Y'));

        try {
            $response = Http::timeout(config('glaust.cbar.timeout'))
                ->withOptions(['verify' => true])
                ->accept('application/xml')
                ->get($url);
        } catch (\Throwable $e) {
            Log::error('CBAR request failed for '.$date->format('d.m.Y').': '.$e->getMessage());

            return ['date' => null, 'rates' => []];
        }

        if ($response->status() !== 200) {
            Log::error('CBAR returned HTTP '.$response->status().' for '.$date->format('d.m.Y'));

            return ['date' => null, 'rates' => []];
        }

        return ['date' => $this->bulletinDate($response->body()), 'rates' => $this->parse($response->body())];
    }

    /**
     * CBAR XML -> rates per ONE unit. CBAR quotes some currencies per 100 (RUB, JPY...),
     * so rate = Value / Nominal, rounded to 8 decimals.
     */
    public function parse(string $xml): array
    {
        $doc = $this->load($xml);
        $rates = [];
        if (! $doc) {
            return $rates;
        }

        foreach ($doc->ValType as $valType) {
            foreach ($valType->Valute as $valute) {
                $code = strtoupper(trim((string) $valute['Code']));
                $nominal = (float) str_replace(',', '.', trim((string) $valute->Nominal));
                $value = (float) str_replace(',', '.', trim((string) $valute->Value));
                if ($code !== '' && $nominal > 0 && $value > 0) {
                    $rates[$code] = [
                        'rate' => round($value / $nominal, 8),
                        'nominal' => $nominal,
                        'value' => $value,
                        'name' => trim((string) $valute->Name),
                    ];
                }
            }
        }

        return $rates;
    }

    /** ValCurs@Date (dd.mm.yyyy) as Y-m-d, or null. */
    public function bulletinDate(string $xml): ?string
    {
        $doc = $this->load($xml);
        $raw = $doc ? trim((string) $doc['Date']) : '';
        $d = \DateTime::createFromFormat('!d.m.Y', $raw);

        return $d && $d->format('d.m.Y') === $raw ? $d->format('Y-m-d') : null;
    }

    private function load(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc ?: null;
    }
}
