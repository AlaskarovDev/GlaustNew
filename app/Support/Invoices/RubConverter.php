<?php

namespace App\Support\Invoices;

use App\Models\Invoice;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Validation\ValidationException;

/**
 * Sets the invoice-currency -> RUB rate used by the RUR columns (the sheet's =L*$D$18/$D$19).
 * D18 = AZN per unit of the invoice currency, D19 = AZN per rouble:
 *   cbar   — both from the CBAR bulletin of the chosen date (never another day's rates);
 *   manual — both typed in, e.g. a rate agreed in the contract.
 */
class RubConverter
{
    public const SOURCES = ['cbar' => 'CBAR məzənnəsi', 'manual' => 'Əl ilə (razılaşdırılmış)'];

    public function __construct(private CurrencyRates $rates) {}

    public function apply(Invoice $invoice, string $source, ?string $date, ?float $baseAzn = null, ?float $rubAzn = null): void
    {
        $bulletin = null;
        if ($source === 'cbar') {
            try {
                $snap = $this->rates->snapshot($date);
                $baseAzn = $invoice->currency === 'AZN' ? 1.0 : ($snap['rates'][$invoice->currency]['rate'] ?? throw RateUnavailable::for($invoice->currency, $date));
                $rubAzn = $snap['rates']['RUB']['rate'] ?? throw RateUnavailable::for('RUB', $date);
                $bulletin = $snap['bulletin'] ?? null;
            } catch (RateUnavailable $e) {
                throw ValidationException::withMessages(['fx_date' => $e->getMessage().' Çevirmə yadda saxlanmadı.']);
            }
        }
        if (! $baseAzn || ! $rubAzn || $baseAzn <= 0 || $rubAzn <= 0) {
            throw ValidationException::withMessages(['fx_base_azn' => 'Hər iki məzənnə müsbət olmalıdır.']);
        }

        $invoice->update([
            'fx_source' => $source, 'fx_date' => $date, 'fx_bulletin_date' => $bulletin,
            'fx_base_azn' => $baseAzn, 'fx_target_azn' => $rubAzn, 'fx_rate' => $baseAzn / $rubAzn, 'fx_updated_at' => now(),
        ]);
    }

    public function clear(Invoice $invoice): void
    {
        $invoice->update(['fx_source' => null, 'fx_date' => null, 'fx_bulletin_date' => null, 'fx_base_azn' => null,
            'fx_target_azn' => null, 'fx_rate' => null, 'fx_updated_at' => null]);
    }
}
