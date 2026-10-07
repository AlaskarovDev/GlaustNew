<?php

namespace App\Imports\Atf;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the company's ATF workbook (sheet «ATF»): one row per Trade — Ellis → Axios, with the money in,
 * the currency sold / bought, the payment to the seller and the logistics payment. Only the input columns
 * are read; the sheet's own formulas are recomputed by the system.
 */
class AtfSheet
{
    /** column letter => [field, kind, label] */
    public const COLUMNS = [
        'A' => ['order', 'text', 'Sifariş №'],
        'B' => ['seller_no', 'text', 'Satıcı fakturası №'],
        'C' => ['seller_date', 'date', 'Satıcı fakturasının tarixi'],
        'D' => ['seller_amount', 'number', 'Satıcı fakturası (EUR)'],
        'E' => ['seller_paid_date', 'date', 'Satıcıya ödəniş tarixi'],
        'F' => ['buyer_no', 'text', 'Alıcı fakturası №'],
        'G' => ['buyer_date', 'date', 'Alıcı fakturasının tarixi'],
        'H' => ['buyer_amount', 'number', 'Alıcı fakturası (RUB)'],
        'I' => ['logistics_forecast', 'number', 'Logistika proqnozu (EUR)'],
        'J' => ['seller_fee', 'number', 'Köçürmə komissiyası (EUR)'],
        'K' => ['invoiced_on', 'date', 'Hesab verilən tarix'],
        'N' => ['forecast_eur', 'number', 'Proqnoz EUR'],
        'O' => ['forecast_rub', 'number', 'Proqnoz RUB'],
        'T' => ['money_in_date', 'date', 'Daxilolma tarixi'],
        'X' => ['operation_date', 'date', 'Əməliyyat tarixi'],
        'AF' => ['bank_eur', 'number', 'Bank EUR kursu'],
        'AG' => ['bank_rub', 'number', 'Bank RUB kursu'],
        'AO' => ['rub_sold', 'number', 'Satılan RUB'],
        'AP' => ['eur_bought', 'number', 'Alınan EUR'],
        'AX' => ['logistics_paid', 'number', 'Logistikaya ödənilən (RUB)'],
        'AY' => ['logistics_date', 'date', 'Logistika ödəniş tarixi'],
        'BA' => ['logistics_rate', 'number', 'Logistika bank kursu (1 RUB = AZN)'],
        'BB' => ['logistics_fee_azn', 'number', 'Logistika komissiyası (AZN)'],
        'BF' => ['logistics_no', 'text', 'Logistika invoysu №'],
        'BG' => ['logistics_inv_date', 'date', 'Logistika invoysunun tarixi'],
        'BH' => ['act_no', 'text', 'Akt №'],
        'BI' => ['act_date', 'date', 'Akt tarixi'],
    ];

    /** Row 2 of the company's ATF sheet, column by column — the template keeps it as is, so rows can be pasted over. */
    public const HEADERS = [
        'A' => 'Номера заказов', 'B' => 'Номер инвойса Ellis', 'C' => 'Дата инвойса Ellis', 'D' => 'Цена инвойса Ellis', 'E' => 'Дата оплаты',
        'F' => 'Номер инвойса AXIOS', 'G' => 'Дата инвойса AXIOS', 'H' => 'Цена инвойса AXIOS', 'I' => 'Логистика proqnoz', 'J' => 'KOCURME KOMİSSİONU',
        'K' => 'HESAB VERILƏN TARIX', 'L' => 'MB EUR', 'M' => 'MB RUB', 'N' => 'PROQNOZ EUR', 'O' => 'PROQNOZ RUBL', 'P' => 'BANK EUR/AZN', 'Q' => 'BANK RUBL/AZN',
        'R' => 'LOGISTIKA XƏRC AZN', 'S' => 'KÖÇÜRMƏ KOMISSIYASI 0,25% AZN', 'T' => 'VESAIT  DAXIL OLAN TARIX', 'U' => 'MB EUR', 'V' => 'Vəsait daxil olan günə(MB RUB)',
        'W' => 'Vəsait daxil olan günə(MB RUB) azn', 'X' => 'ƏMƏLIYYAT TARIXI', 'Y' => 'CB EUR', 'Z' => 'CB RUR', 'AA' => 'Əməliyyat tarixinə cb eur',
        'AB' => 'KOCURULME TARIXINE MB  ILEEUR/ AZN', 'AC' => 'Əməliyyat tarixinə cb rur/AZN', 'AD' => 'SATILAN RUBLA GORE Əməliyyat tarixinə cb rur',
        'AE' => 'ƏMƏLİYYAT GÜNÜNƏ GƏLİR', 'AF' => 'FAKT EUR', 'AG' => 'FAKT RUBL', 'AH' => 'BANK EUR/AZN', 'AI' => 'BANK RUBL/AZN',
        'AJ' => 'KÖÇÜRMƏ KOMISSIYASI 0,25% CB AZN', 'AK' => 'KÖÇÜRMƏ KOMISSIYASI 0,25% Bank AZN', 'AL' => 'RUBL MEZENNE FERQI XƏRC', 'AM' => 'EUR MEZENNE FERQI XƏRC',
        'AN' => 'Kocurme komissiyasina esasen Kurs ferqi', 'AO' => 'SATILAN RUR', 'AP' => 'ALINAN EUR', 'AQ' => 'XALIS MENFEET CASH FLOWW', 'AR' => 'RUBL GELIR',
        'AS' => 'EUR GELIR', 'AT' => 'GELIR', 'AU' => 'KOCURME KOM GELIR', 'AV' => 'LOG XERC GELIR', 'AW' => 'YOXLAMA KURS FERQI', 'AX' => 'ODENIS LOGISTIKA',
        'AY' => 'Log OdemeTARIX', 'AZ' => 'MB EUR', 'BA' => 'MB Rubl', 'BB' => 'LOGISTIKA KOMISSIYA (AZN)', 'BC' => 'Log Rub/Azn', 'BD' => 'Log Eur/Azn', 'BE' => 'Gəlir',
        'BF' => 'INV N', 'BG' => 'INV TARIX', 'BH' => 'AKT N', 'BI' => 'AKT TARIX', 'BJ' => 'MB EUR AKTIN TARIXI', 'BK' => 'MB RUR AKTIN TARIXI ',
        'BL' => 'MB EUR/AZN  AKTIN TARIXI', 'BM' => 'MB RUR/AZN AKTIN TARIXI ', 'BN' => 'AKTİN TARİXİNƏ GƏLİR', 'BO' => 'AKTIN TARIXINE LOGISTIKA AZN MB', 'BP' => '',
        'BQ' => 'MENFI MEZENNE FERQI', 'BR' => 'MUSBET MEZENNE FERQI', 'BS' => 'LOGISTIKA MEZENNE FERQI', 'BT' => 'XALIS MENFEET',
    ];

    /**
     * The import template: sheet «ATF» with the sheet's own row-2 headers in the same columns (A…BT), so whole rows
     * copied from the company's workbook can be pasted from row 3. Green — read by the import; grey — computed by the system.
     */
    public static function template(): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('ATF');
        $sheet->setCellValue('A1', __('TradeFlow — toplu Trade importu. 3-cü sətirdən etibarən ATF cədvəlinizin sətirlərini olduğu kimi yapışdırın. Yaşıl sütunlar oxunur, boz sütunlar sistemdə hesablanır (boş qala bilər).'));
        $sheet->mergeCells('A1:T1');
        $sheet->getStyle('A1')->getFont()->setItalic(true)->setSize(10);
        foreach (self::HEADERS as $col => $title) {
            $sheet->setCellValue($col.'2', $title);
            $read = isset(self::COLUMNS[$col]);
            $style = $sheet->getStyle($col.'2');
            $style->getFont()->setBold(true)->setSize(10)->getColor()->setRGB($read ? '14532D' : '6B7280');
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($read ? '92D050' : 'E5E7EB');
            $style->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getColumnDimension($col)->setWidth(in_array($col, ['B', 'F', 'BF', 'BH'], true) ? 18 : 14);
            if ($read && self::COLUMNS[$col][1] === 'date') {
                $sheet->getStyle($col.'3:'.$col.'500')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            }
        }
        $sheet->getRowDimension(2)->setRowHeight(48);
        $sheet->freezePane('C3');

        return $book;
    }

    /** Header text the ATF template has (row 2), to recognise the sheet. */
    private const SIGNATURE = ['B' => 'инвойса', 'H' => 'инвойса', 'AX' => 'LOGISTIKA', 'BI' => 'AKT'];

    /** @return array{rows: list<array>, errors: list<string>} */
    public static function parse(string $path): array
    {
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('ATF') ?? $book->getSheet(0);
        $headerRow = self::headerRow($sheet);
        if ($headerRow === null) {
            return ['rows' => [], 'errors' => [__('ATF cədvəli tanınmadı: «ATF» vərəqində B sütununda «Номер инвойса», AX-da «ODENIS LOGISTIKA», BI-də «AKT TARIX» başlıqları olmalıdır.')]];
        }

        $rows = [];
        $max = $sheet->getHighestDataRow();
        for ($r = $headerRow + 1; $r <= $max; $r++) {
            $row = ['line' => $r];
            foreach (self::COLUMNS as $col => [$field, $kind]) {
                $row[$field] = self::value($sheet->getCell($col.$r), $kind);
            }
            if ($row['seller_no'] === null && $row['seller_amount'] === null) {
                continue;   // empty line
            }
            $rows[] = self::check($row);
        }

        return ['rows' => $rows, 'errors' => $rows ? [] : [__('Cədvəldə Trade sətri tapılmadı.')]];
    }

    private static function headerRow(Worksheet $sheet): ?int
    {
        for ($r = 1; $r <= 6; $r++) {
            $ok = true;
            foreach (self::SIGNATURE as $col => $needle) {
                if (! str_contains(mb_strtolower((string) $sheet->getCell($col.$r)->getValue()), mb_strtolower($needle))) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $r;
            }
        }

        return null;
    }

    /** The value Excel shows: a formula's cached result, dates as Y-m-d (also "15.06.26" typed as text). */
    private static function value(Cell $cell, string $kind): string|float|null
    {
        $v = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
        if ($v === null || $v === '' || (is_string($v) && str_starts_with($v, '#'))) {
            return null;
        }
        if ($kind === 'date') {
            if (is_numeric($v)) {
                return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            }
            foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y'] as $f) {
                $d = \DateTime::createFromFormat('!'.$f, trim((string) $v));
                if ($d && $d->format($f) === trim((string) $v)) {
                    return $d->format('Y-m-d');
                }
            }

            return null;
        }
        if ($kind === 'number') {
            return is_numeric($v) ? round((float) $v, 6) : (is_numeric($n = str_replace([' ', ','], ['', '.'], (string) $v)) ? round((float) $n, 6) : null);
        }

        return trim(is_float($v) && floor($v) == $v ? (string) (int) $v : (string) $v);
    }

    /** What the row lacks and how it is filled in. */
    private static function check(array $row): array
    {
        $errors = $notes = [];
        foreach (['seller_no', 'seller_date', 'seller_amount', 'buyer_amount'] as $f) {
            if ($row[$f] === null) {
                $errors[] = __(':v1 yoxdur', ['v1' => self::label($f)]);
            }
        }
        if (! $row['seller_paid_date'] && $row['operation_date']) {
            $notes[] = __('Satıcıya ödəniş tarixi yoxdur — əməliyyat tarixi (X) götürülür.');
        }
        if (! $row['seller_paid_date'] && ! $row['operation_date']) {
            $notes[] = __('Satıcıya ödəniş tarixi yoxdur — ödəniş yazılmayacaq.');
        }
        if ($row['seller_fee'] === null) {
            $notes[] = __('Köçürmə komissiyası yoxdur — bankın qaydası ilə hesablanacaq.');
        }
        if ($row['logistics_paid'] && $row['logistics_fee_azn'] === null) {
            $notes[] = __('Logistika komissiyası yoxdur — bankın qaydası ilə hesablanacaq.');
        }
        if ($row['logistics_paid'] && ! $row['act_date']) {
            $notes[] = __('Akt hələ yoxdur — logistika yalnız invoys kimi yazılır.');
        }
        foreach (['money_in_date', 'operation_date', 'seller_paid_date', 'logistics_date', 'logistics_inv_date', 'act_date', 'seller_date', 'invoiced_on'] as $f) {
            if ($row[$f] && $row[$f] > today()->toDateString()) {
                $errors[] = __(':v1 gələcəkdədir', ['v1' => self::label($f)]);
            }
        }

        return $row + ['errors' => $errors, 'notes' => $notes];
    }

    public static function label(string $field): string
    {
        foreach (self::COLUMNS as $col => [$f, , $label]) {
            if ($f === $field) {
                return __($label).' ('.$col.')';
            }
        }

        return $field;
    }
}
