<?php

namespace App\Services\Cbar;

/**
 * No official CBAR rate exists (or could be loaded) for the requested currency and date.
 * Financial code must surface this to the user — there are deliberately no fallback rates.
 */
class RateUnavailable extends \RuntimeException
{
    public static function for(string $currency, string $date, string $reason = ''): self
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        $shown = $d ? $d->format('d.m.Y') : $date;

        return new self(trim("Mərkəzi Bankın {$shown} tarixli {$currency} məzənnəsi tapılmadı. {$reason}"));
    }
}
