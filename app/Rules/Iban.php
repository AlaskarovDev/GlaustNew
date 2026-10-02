<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ISO 13616 IBAN with mod-97 checksum (AZ IBANs are 28 characters). */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid((string) $value)) {
            $fail('IBAN düzgün deyil (yoxlama rəqəmləri uyğun gəlmir).');
        }
    }

    public static function normalize(?string $iban): ?string
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));

        return $iban === '' ? null : $iban;
    }

    public static function valid(string $iban): bool
    {
        $iban = self::normalize($iban) ?? '';
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'AZ') && strlen($iban) !== 28) {
            return false;
        }
        $moved = substr($iban, 4).substr($iban, 0, 4);
        $digits = '';
        foreach (str_split($moved) as $ch) {
            $digits .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $mod = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $mod = (int) (($mod.$chunk) % 97);
        }

        return $mod === 1;
    }
}
