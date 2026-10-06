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
 *   forecast — the same two AZN rates typed in as a forecast ("Proq EUR" / "Proq RUB" in the sheet).
 */
class RubConverter
{
    public const SOURCES = ['cbar' => 'CBAR kursu', 'forecast' => 'Proqnoz'];

    public function __construct(private CurrencyRates $rates) {}

    /** Target currency of an invoice's conversion: its Trade's sale currency (RUB by default). */
    public static function target(Invoice $invoice): string
    {
        return $invoice->deal?->saleCurrency() ?? 'RUB';
    }

    /**
     * 1 {currency} = ? {target} at CBAR of the day; `rub` = AZN per unit of the target (named after the sheet's D19).
     *
     * @return array{rate: float, base: float, rub: float, bulletin: ?string} @throws RateUnavailable
     */
    public function cbar(string $currency, string $date, string $target = 'RUB'): array
    {
        $snap = $this->rates->snapshot($date);
        $base = $currency === 'AZN' ? 1.0 : ($snap['rates'][$currency]['rate'] ?? throw RateUnavailable::for($currency, $date));
        $rub = $target === 'AZN' ? 1.0 : ($snap['rates'][$target]['rate'] ?? throw RateUnavailable::for($target, $date));

        return ['rate' => $base / $rub, 'base' => $base, 'rub' => $rub, 'bulletin' => $snap['bulletin'] ?? null];
    }

    public function apply(Invoice $invoice, string $source, string $date, ?float $baseAzn = null, ?float $rubAzn = null): void
    {
        if ($source === 'cbar') {
            try {
                $c = $this->cbar($invoice->currency, $date, self::target($invoice));
            } catch (RateUnavailable $e) {
                throw ValidationException::withMessages(['fx_date' => $e->getMessage().__(' Gələcək tarix üçün «Proqnoz» seçin.')]);
            }
            $values = ['fx_bulletin_date' => $c['bulletin'], 'fx_base_azn' => $c['base'], 'fx_target_azn' => $c['rub'], 'fx_rate' => $c['rate']];
        } else {
            if (! $baseAzn || ! $rubAzn || $baseAzn <= 0 || $rubAzn <= 0) {
                throw ValidationException::withMessages(['fx_base_azn' => __('Hər iki proqnoz kursunu daxil edin.')]);
            }
            $values = ['fx_bulletin_date' => null, 'fx_base_azn' => $baseAzn, 'fx_target_azn' => $rubAzn, 'fx_rate' => $baseAzn / $rubAzn];
        }

        $invoice->update(['fx_source' => $source, 'fx_date' => $date, 'fx_updated_at' => now()] + $values);
    }

    /**
     * For a forecast: what CBAR published for its date (null while it has not).
     *
     * @return array{rate: float, base: float, rub: float, bulletin: ?string}|null
     */
    public function actualFor(Invoice $invoice): ?array
    {
        if ($invoice->fx_source !== 'forecast' || ! $invoice->fx_date || $invoice->fx_date->isFuture()) {
            return null;
        }
        try {
            return $this->cbar($invoice->currency, $invoice->fx_date->toDateString(), self::target($invoice));
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
