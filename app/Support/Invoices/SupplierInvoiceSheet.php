<?php

namespace App\Support\Invoices;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

/**
 * The seller's proforma as an Excel sheet: the downloadable template and its parser.
 *
 * Layout follows the company's own working sheet. The seller fills only the first
 * eight columns (Proforma N … Total/EUR); the rest are computed by Glaust later
 * (logistics, our commission %, CCL; RUR rules still to be specified), so in the template they
 * are grey, locked and marked "DOLDURMAYIN".
 */
class SupplierInvoiceSheet
{
    /** key => [header, fillable, width] — order = template column order */
    public const COLUMNS = [
        'proforma' => ['Proforma N', true, 13],
        'line_no' => ['N', true, 6],
        'description' => ['Description', true, 38],
        'hs_code' => ['HS Code', true, 13],
        'quantity' => ['Quantity', true, 12],
        'uom' => ['UOM', true, 8],
        'unit_price' => ['Unit Price', true, 12],
        'total' => ['Total/EUR', true, 14],
        'logistics' => ['Logistics', false, 12],
        'unit_price_log' => ['UNIT PRICE+LOG', false, 14],
        'fee' => ['Commission %', false, 12],
        'unit_price_ccl_eur' => ['UNIT PRICE CCL EUR', false, 14],
        'total_ccl_eur' => ['TOTAL PRICE CCL EUR', false, 15],
        'unit_price_rur' => ['UNIT PRICE RUR', false, 14],
        'total_rur' => ['TOTAL PRICE RUR', false, 16],
        'unit_price_rur_rounded' => ['UNIT PRICE RUR', false, 14],
        'total_rur_rounded' => ['TOTAL PRICE RUR', false, 16],
    ];

    private const HEADER_ROW = 5;

    private const BLANK_ROWS = 60;

    public static function template(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('TradeFlow')->setTitle('Satıcının fakturası — şablon');
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Faktura');
        $cols = array_values(self::COLUMNS);
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $fillLast = Coordinate::stringFromColumnIndex(count(array_filter($cols, fn ($c) => $c[1])));
        $firstLocked = Coordinate::stringFromColumnIndex(count(array_filter($cols, fn ($c) => $c[1])) + 1);
        $endRow = self::HEADER_ROW + self::BLANK_ROWS;

        $sheet->setCellValue('A1', 'Satıcının fakturası (proforma) — TradeFlow import şablonu');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', "YALNIZ yaşıl başlıqlı sütunları doldurun: Proforma N, N, Description, HS Code, Quantity, UOM, Unit Price, Total/EUR (daxil olmaqla).");
        $sheet->getStyle('A2')->getFont()->setBold(true)->getColor()->setRGB('0B6B5F');
        $sheet->setCellValue('A3', "Boz sütunları (Logistics və sonrakılar) DOLDURMAYIN — onları sistem hesablayacaq. Rəqəmləri rəqəm kimi yazın (məs. 3200 və ya 4,89). Boş sətirlər nəzərə alınmır. Sətirlərin sırasını və başlıqları dəyişməyin.");
        $sheet->getStyle('A3')->getFont()->getColor()->setRGB('B42318');
        foreach (['A2', 'A3'] as $c) {
            $sheet->mergeCells($c.':'.$last.substr($c, 1));
            $sheet->getStyle($c)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        }
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(32);

        $sheet->setCellValue('A4', 'DOLDURUN ↓');
        $sheet->mergeCells('A4:'.$fillLast.'4');
        $sheet->setCellValue($firstLocked.'4', 'DOLDURMAYIN — sistem hesablayacaq ↓');
        $sheet->mergeCells($firstLocked.'4:'.$last.'4');
        $sheet->getStyle('A4:'.$last.'4')->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle('A4:'.$fillLast.'4')->getFont()->getColor()->setRGB('0B6B5F');
        $sheet->getStyle($firstLocked.'4:'.$last.'4')->getFont()->getColor()->setRGB('667085');
        $sheet->getStyle('A4:'.$last.'4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $h = self::HEADER_ROW;
        foreach ($cols as $i => [$header, $fillable, $width]) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($letter.$h, $header);
            $sheet->getColumnDimension($letter)->setWidth($width);
            $sheet->getStyle($letter.$h)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => $fillable ? 'FFFFFF' : '475467'], 'size' => 9],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillable ? '0F8F7E' : 'D0D5DD']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $range = $letter.($h + 1).':'.$letter.$endRow;
            if ($fillable) {
                $sheet->getStyle($range)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
            } else {
                $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F4F7');
            }
            $format = match ($i) {
                4 => '#,##0.00',
                6 => '#,##0.00##',
                7 => '#,##0.00',
                default => null,
            };
            if ($format) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }
        }
        $sheet->getRowDimension($h)->setRowHeight(30);
        $sheet->getStyle('A'.$h.':'.$last.$endRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D0D5DD');
        $sheet->getStyle('C'.($h + 1).':C'.$endRow)->getAlignment()->setWrapText(true);
        $sheet->freezePane('A'.($h + 1));

        // Grey columns cannot be typed into; the green ones stay editable.
        $sheet->getProtection()->setSheet(true)->setFormatColumns(false)->setFormatRows(false)->setInsertRows(false)->setDeleteRows(false)->setSort(false)->setAutoFilter(false);

        // A second sheet with the example from the company's own file.
        $ex = $book->createSheet();
        $ex->setTitle('Nümunə');
        $ex->setCellValue('A1', 'Nümunə (bu vərəq import edilmir)');
        $ex->getStyle('A1')->getFont()->setBold(true);
        foreach (array_slice($cols, 0, 8) as $i => [$header]) {
            $ex->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'3', $header);
        }
        $ex->getStyle('A3:H3')->getFont()->setBold(true);
        $rows = [
            ['221619', 1, 'TD-Weiss Migrastar Gr.1/S/IPA', '32151900', 3200, 'kg', 4.89, 15648],
            ['221619', 2, 'Supra EB Cyan Folie FCM', '32151900', 3200, 'kg', 11.08, 35456],
            ['221619', 3, 'Supra EB Gelb Folie FCM', '32151900', 7400, 'kg', 11.08, 81992],
        ];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $v) {
                $cell = Coordinate::stringFromColumnIndex($c + 1).($r + 4);
                in_array($c, [0, 3], true) ? $ex->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING) : $ex->setCellValue($cell, $v);
            }
        }
        foreach (range('A', 'H') as $col) {
            $ex->getColumnDimension($col)->setAutoSize(true);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Reads the first sheet (or the one named "Faktura"). The header row is located by
     * its "Proforma" cell anywhere in the first 15 rows, and columns are matched by
     * header text, so the company's own working sheet imports as well as the template.
     *
     * @return array{invoices: array<string, array<int, array>>, errors: array<int, string>, rows: int}
     */
    public static function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->getSheetByName('Faktura') ?? $book->getSheet(0);
        $data = $sheet->toArray(null, true, false, false);

        $norm = fn ($s) => preg_replace('/[^a-z0-9%]/', '', strtolower((string) $s));
        $headerIndex = null;
        foreach (array_slice($data, 0, 15, true) as $i => $row) {
            if (str_starts_with($norm($row[0] ?? ''), 'proforma')) {
                $headerIndex = $i;
                break;
            }
        }
        if ($headerIndex === null) {
            return ['invoices' => [], 'errors' => [0 => __('Başlıq sətri tapılmadı: birinci sütunda «Proforma N» olmalıdır.')], 'rows' => 0];
        }

        // Only the eight seller columns are read; the first occurrence of each header wins.
        $wanted = array_slice(self::COLUMNS, 0, 8, true);
        $map = [];
        foreach ($data[$headerIndex] as $col => $header) {
            foreach ($wanted as $key => [$label]) {
                if (! isset($map[$key]) && $norm($header) === $norm($label)) {
                    $map[$key] = $col;
                }
            }
        }
        $missing = array_diff(array_keys($wanted), array_keys($map));
        if ($missing) {
            return ['invoices' => [], 'errors' => [0 => __('Sütunlar tapılmadı: ').implode(', ', array_map(fn ($k) => $wanted[$k][0], $missing))], 'rows' => 0];
        }

        $invoices = [];
        $errors = [];
        $count = 0;
        foreach (array_slice($data, $headerIndex + 1, null, true) as $i => $row) {
            $line = $i + 1;
            $get = fn ($k) => is_string($row[$map[$k]] ?? null) ? trim($row[$map[$k]]) : ($row[$map[$k]] ?? null);
            $proforma = (string) ($get('proforma') ?? '');
            $description = (string) ($get('description') ?? '');
            if ($proforma === '' && $description === '') {
                continue; // blank row or the totals row at the bottom
            }
            $count++;
            $qty = parse_number($get('quantity'));
            $price = parse_number($get('unit_price'));
            $total = parse_number($get('total'));

            $problems = [];
            if ($proforma === '') {
                $problems[] = __('Proforma N boşdur');
            }
            if ($description === '') {
                $problems[] = __('Description boşdur');
            }
            if ($qty === null || $qty <= 0) {
                $problems[] = __('Quantity müsbət rəqəm olmalıdır');
            }
            if ($price === null || $price < 0) {
                $problems[] = __('Unit Price rəqəm olmalıdır');
            }
            if ($total === null) {
                $problems[] = __('Total/EUR boşdur');
            } elseif ($qty !== null && $price !== null && abs($qty * $price - $total) > max(0.05, abs($total) * 0.005)) {
                $problems[] = 'Total/EUR ('.num($total).') Quantity × Unit Price ('.num($qty * $price).__(') ilə uyğun gəlmir');
            }
            if ($problems) {
                $errors[$line] = implode('; ', $problems);

                continue;
            }

            $invoices[$proforma][] = [
                'line_no' => (int) (parse_number($get('line_no')) ?: count($invoices[$proforma] ?? []) + 1),
                'description' => mb_substr($description, 0, 255),
                'hs_code' => mb_substr((string) ($get('hs_code') ?? ''), 0, 20) ?: null,
                'quantity' => round($qty, 3),
                'uom' => mb_substr((string) ($get('uom') ?? ''), 0, 16) ?: null,
                'unit_price' => round($price, 4),
                'total' => round($total, 2),
            ];
        }
        $book->disconnectWorksheets();

        if (! $count) {
            $errors[0] = __('Faylda məlumat sətri yoxdur.');
        }

        return ['invoices' => $invoices, 'errors' => $errors, 'rows' => $count];
    }
}
