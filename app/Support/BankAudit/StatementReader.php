<?php

namespace App\Support\BankAudit;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads a bank statement in the bank's own Excel layout («Hesabdan çıxarış / Account statement»):
 *   a header — «Hesab nömrəsi / IBAN», «Hesabın valyutası», «İlk / Son əməliyyat tarixi», «Günün əvvəlinə / sonuna qalıq»
 *   (each value in the first filled cell to the right of its label) — then the table
 *   «№ | Tarix | Hesab nömrəsi | Məbləğ | Məbləğin AZN dəyəri | Qalıq | Qalığın AZN dəyəri | Valyuta | Təyinat»,
 *   newest first, signed amounts, closed by a «Cəmi» row.
 */
class StatementReader
{
    /**
     * @return array{iban: ?string, currency: string, from: string, to: string, opening: float, closing: float,
     *               lines: list<array{no: int, date: string, amount: float, azn: ?float, balance: ?float, description: string}>}
     *
     * @throws \RuntimeException with a message for the user when the file is not such a statement
     */
    public static function read(string $path): array
    {
        try {
            $rows = IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable) {
            throw new \RuntimeException(__('Fayl oxunmadı. Bankın Excel çıxarışını (.xlsx) yükləyin.'));
        }

        $label = function (string $needle) use ($rows) {
            foreach ($rows as $row) {
                foreach ($row as $i => $cell) {
                    if (is_string($cell) && str_contains(mb_strtolower($cell), mb_strtolower($needle))) {
                        for ($j = $i + 1; $j < count($row); $j++) {
                            if ($row[$j] !== null && $row[$j] !== '') {
                                return $row[$j];
                            }
                        }
                    }
                }
            }

            return null;
        };

        // the table: the row that starts with «№» and has «Tarix»
        $head = null;
        foreach ($rows as $r => $row) {
            if (trim((string) ($row[0] ?? '')) === '№' && in_array('Tarix', array_map(fn ($c) => trim((string) $c), $row), true)) {
                $head = $r;
                break;
            }
        }
        if ($head === null) {
            throw new \RuntimeException(__('Çıxarış cədvəli tapılmadı: «№ | Tarix | … | Məbləğ | … | Təyinat» başlıqlı sətir olmalıdır.'));
        }
        $col = [];
        foreach ($rows[$head] as $i => $name) {
            $col[trim((string) $name)] ??= $i;
        }
        foreach (['Tarix', 'Məbləğ'] as $need) {
            if (! isset($col[$need])) {
                throw new \RuntimeException(__('Çıxarışda «:v1» sütunu yoxdur.', ['v1' => $need]));
            }
        }

        $currency = strtoupper(trim((string) $label('Hesabın valyutası')));
        $lines = [];
        foreach (array_slice($rows, $head + 1) as $row) {
            $date = self::date($row[$col['Tarix']] ?? null);
            if ($date === null) {
                if (collect($row)->contains(fn ($c) => is_string($c) && trim($c) === 'Cəmi')) {
                    break;
                }

                continue;
            }
            $amount = self::num($row[$col['Məbləğ']] ?? null);
            if ($amount === null) {
                continue;
            }
            $lines[] = [
                'no' => (int) self::num($row[0] ?? count($lines)),
                'date' => $date,
                'amount' => round($amount, 2),
                'azn' => isset($col['Məbləğin AZN dəyəri']) ? self::num($row[$col['Məbləğin AZN dəyəri']] ?? null) : null,
                'balance' => isset($col['Qalıq']) ? self::num($row[$col['Qalıq']] ?? null) : null,
                'description' => trim(preg_replace('/\s*\n\s*/', '', (string) ($row[$col['Təyinat'] ?? -1] ?? ''))),
            ];
            if (! $currency && isset($col['Valyuta'])) {
                $currency = strtoupper(trim((string) $row[$col['Valyuta']]));
            }
        }
        if (! $lines) {
            throw new \RuntimeException(__('Çıxarışda əməliyyat sətri yoxdur.'));
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException(__('Hesabın valyutası tapılmadı.'));
        }
        $dates = array_column($lines, 'date');

        return [
            'iban' => ($iban = preg_replace('/\s+/', '', (string) $label('IBAN'))) !== '' ? strtoupper($iban) : null,
            'currency' => $currency,
            'from' => self::date($label('İlk əməliyyat tarixi')) ?? min($dates),
            'to' => self::date($label('Son əməliyyat tarixi')) ?? max($dates),
            'opening' => round((float) self::num($label('Günün əvvəlinə qalıq')), 2),
            'closing' => round((float) self::num($label('Günün sonuna qalıq')), 2),
            'lines' => $lines,
        ];
    }

    /** «30-12-2025», «2025-12-30», «30.12.2025» or an Excel date → Y-m-d. */
    private static function date($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_numeric($v) && $v > 20000 && $v < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $v = trim((string) $v);
        if (preg_match('/^(\d{2})[-.\/](\d{2})[-.\/](\d{4})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        return null;
    }

    private static function num($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = str_replace([' ', "\u{00A0}"], '', trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',') && ! str_contains($v, '.')) {
            $v = str_replace(',', '.', $v);
        }
        $v = str_replace(',', '', $v);

        return is_numeric($v) ? (float) $v : null;
    }
}
