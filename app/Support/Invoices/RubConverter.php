<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Validation\ValidationException;

/**
 * Sets the invoice-currency -> RUB rate used by the RUR columns (the sheet's =L*$D$18/$D$19),
 * for the date our invoice to the buyer will be issued:
 *   cbar     — D18 / D19 from the CBAR bulletin of that date (AZN per unit of each currency);
 *              a future date has no bulletin yet, so it is refused, never filled with another day's rate;
 *   forecast — 1 unit of the invoice currency = X RUB, typed in for a date CBAR has not published.
 */
class RubConverter
{
    public const SOURCES = ['cbar' => 'CBAR kursu', 'forecast' => 'Proqnoz'];

    public function __construct(private CurrencyRates $rates) {}

    /** @return array{rate: float, base: float, rub: float, bulletin: ?string} @throws RateUnavailable */
    public function cbar(string $currency, string $date): array
    {
        $snap = $this->rates->snapshot($date);
        $base = $currency === 'AZN' ? 1.0 : ($snap['rates'][$currency]['rate'] ?? throw RateUnavailable::for($currency, $date));
        $rub = $snap['rates']['RUB']['rate'] ?? throw RateUnavailable::for('RUB', $date);

        return ['rate' => $base / $rub, 'base' => $base, 'rub' => $rub, 'bulletin' => $snap['bulletin'] ?? null];
    }

    public function apply(Invoice $invoice, string $source, string $date, ?float $forecast = null): void
    {
        if ($source === 'cbar') {
            try {
                $c = $this->cbar($invoice->currency, $date);
            } catch (RateUnavailable $e) {
                throw ValidationException::withMessages(['fx_date' => $e->getMessage().' Gələcək tarix üçün «Proqnoz» seçin.']);
            }
            $values = ['fx_bulletin_date' => $c['bulletin'], 'fx_base_azn' => $c['base'], 'fx_target_azn' => $c['rub'], 'fx_rate' => $c['rate']];
        } else {
            if (! $forecast || $forecast <= 0) {
                throw ValidationException::withMessages(['fx_forecast' => 'Proqnoz kursunu daxil edin.']);
            }
            $values = ['fx_bulletin_date' => null, 'fx_base_azn' => null, 'fx_target_azn' => null, 'fx_rate' => $forecast];
        }

        $invoice->update(['fx_source' => $source, 'fx_date' => $date, 'fx_updated_at' => now()] + $values);
    }

    /** For a forecast whose date has come: what CBAR actually published (null while it has not). */
    public function actualFor(Invoice $invoice): ?float
    {
        if ($invoice->fx_source !== 'forecast' || ! $invoice->fx_date || $invoice->fx_date->isFuture()) {
            return null;
        }
        try {
            return $this->cbar($invoice->currency, $invoice->fx_date->toDateString())['rate'];
        } catch (RateUnavailable|\InvalidArgumentException) {
            return null;
        }
    }

    public function clear(Invoice $invoice): void
    {
        $invoice->update(['fx_source' => null, 'fx_date' => null, 'fx_bulletin_date' => null, 'fx_base_azn' => null,
            'fx_target_azn' => null, 'fx_rate' => null, 'fx_updated_at' => null]);
    }
}
