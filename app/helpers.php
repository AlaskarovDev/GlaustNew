<?php

use App\Models\Company;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonInterface;

if (! function_exists('tenant')) {
    function tenant(): ?Company
    {
        return app(Tenant::class)->company();
    }
}

if (! function_exists('currency_symbol')) {
    function currency_symbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'AZN' => '₼', 'USD' => '$', 'EUR' => '€', 'RUB' => '₽', 'TRY' => '₺', 'GBP' => '£',
            'GEL' => '₾', 'CNY', 'JPY' => '¥', 'UAH' => '₴', 'KZT' => '₸',
            default => strtoupper($currency),
        };
    }
}

if (! function_exists('num')) {
    /** 1234567.5 -> "1 234 567,50" (Azerbaijani grouping, non-breaking space). */
    function num(float|int|string|null $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', "\u{00A0}");
    }
}

if (! function_exists('money')) {
    /** "1 234,56 ₼"; $symbol=false gives "1 234,56 AZN" (PDF-safe). */
    function money(float|int|string|null $amount, string $currency = 'AZN', bool $symbol = true): string
    {
        $suffix = $symbol ? currency_symbol($currency) : strtoupper($currency);

        return num($amount).("\u{00A0}").$suffix;
    }
}

if (! function_exists('rate_fmt')) {
    /** Exchange rate with up to 8 decimals, at least 4: 1.7 -> "1,7000", 0.021091 -> "0,021091". */
    function rate_fmt(float|string|null $rate): string
    {
        $s = rtrim(rtrim(number_format((float) $rate, 8, '.', ''), '0'), '.');
        [$int, $frac] = explode('.', $s.'.') + [1 => ''];
        $frac = str_pad($frac, 4, '0');

        return $int.','.$frac;
    }
}

if (! function_exists('azdate')) {
    function azdate(CarbonInterface|DateTimeInterface|string|null $date, bool $withTime = false): string
    {
        if (! $date) {
            return '—';
        }
        $d = $date instanceof DateTimeInterface ? $date : \Carbon\Carbon::parse($date);

        return $d->format($withTime ? 'd.m.Y H:i' : 'd.m.Y');
    }
}

if (! function_exists('az_month')) {
    function az_month(int $month, bool $short = false): string
    {
        $full = ['Yanvar', 'Fevral', 'Mart', 'Aprel', 'May', 'İyun', 'İyul', 'Avqust', 'Sentyabr', 'Oktyabr', 'Noyabr', 'Dekabr'];
        $abbr = ['Yan', 'Fev', 'Mar', 'Apr', 'May', 'İyn', 'İyl', 'Avq', 'Sen', 'Okt', 'Noy', 'Dek'];

        return ($short ? $abbr : $full)[$month - 1] ?? '';
    }
}

if (! function_exists('az_weekday')) {
    function az_weekday(DateTimeInterface $date): string
    {
        return ['Bazar', 'Bazar ertəsi', 'Çərşənbə axşamı', 'Çərşənbə', 'Cümə axşamı', 'Cümə', 'Şənbə'][(int) $date->format('w')];
    }
}

if (! function_exists('status_label')) {
    function status_label(string $group, ?string $key): string
    {
        return config("glaust.statuses.$group.$key.0", (string) $key);
    }
}

if (! function_exists('status_color')) {
    function status_color(string $group, ?string $key): string
    {
        return config("glaust.statuses.$group.$key.1", 'slate');
    }
}

if (! function_exists('status_options')) {
    /** ['key' => 'Label', ...] for a status group. */
    function status_options(string $group): array
    {
        return array_map(fn ($v) => $v[0], config("glaust.statuses.$group", []));
    }
}

if (! function_exists('parse_number')) {
    /**
     * Human / Excel number -> float. Accepts "1 234,56", "1,234.56", "1234.56", "1.234,56".
     * Returns null for empty or unreadable input.
     */
    function parse_number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = preg_replace('/[\s\x{00A0}\x{202F}₼$€₽₺£]/u', '', (string) $value);
        if ($s === '' || $s === null) {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $s = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif ($lastComma !== false) {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }
}

if (! function_exists('az_lower')) {
    /** Case-folding that handles Azerbaijani İ/ı correctly (mb_strtolower turns İ into i + U+0307). */
    function az_lower(string $s): string
    {
        return mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı']));
    }
}
