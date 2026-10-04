<?php

namespace App\Support\Export;

use App\Tables\Column;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * .xlsx export: title block, bold frozen header, real dates and numbers (so Excel
 * can sum and sort them), auto-filter, auto-sized columns and a totals row.
 */
class SpreadsheetExporter
{
    /** @param Column[] $columns */
    public function download(string $title, array $columns, iterable $rows, string $filename, array $filters = []): StreamedResponse
    {
        $book = $this->build($title, $columns, $rows, $filters);

        return new StreamedResponse(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0, no-store',
        ]);
    }

    /** @param Column[] $columns */
    public function build(string $title, array $columns, iterable $rows, array $filters = []): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('TradeFlow')->setTitle($title);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $title), 0, 31));
        $last = Coordinate::stringFromColumnIndex(max(1, count($columns)));

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $meta = 'Tarix: '.now()->format('d.m.Y H:i').(tenant() ? '  ·  '.tenant()->name : '');
        $sheet->setCellValue('A2', $meta.($filters ? '  ·  '.implode('; ', $filters) : ''));
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('667085');

        $headerRow = 4;
        foreach ($columns as $i => $col) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $col->label);
        }
        $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F8F7E']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        $r = $headerRow;
        $totals = [];
        foreach ($rows as $row) {
            $r++;
            foreach ($columns as $i => $col) {
                $this->writeCell($sheet, $i + 1, $r, $col, $col->read($row));
                if ($col->total) {
                    $totals[$i] = ($totals[$i] ?? 0) + (float) $col->read($row);
                }
            }
        }

        if ($r > $headerRow) {
            $sheet->getStyle("A{$headerRow}:{$last}{$r}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E4E7EC');
            $sheet->setAutoFilter("A{$headerRow}:{$last}{$r}");
        }

        if ($totals) {
            $r++;
            $sheet->setCellValue("A{$r}", 'Cəmi');
            foreach ($totals as $i => $sum) {
                $this->writeCell($sheet, $i + 1, $r, $columns[$i], round($sum, 2));
            }
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        $sheet->freezePane('A'.($headerRow + 1));
        foreach ($columns as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $col->width ? $sheet->getColumnDimension($letter)->setWidth($col->width) : $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
        $sheet->getPageSetup()->setOrientation(count($columns) > 6 ? 'landscape' : 'portrait')->setFitToWidth(1)->setFitToHeight(0);

        return $book;
    }

    private function writeCell(Worksheet $sheet, int $colIndex, int $row, Column $col, mixed $value): void
    {
        $cell = Coordinate::stringFromColumnIndex($colIndex).$row;
        if ($value === null || $value === '') {
            return;
        }

        switch ($col->type) {
            case 'money':
            case 'number':
            case 'rate':
                $sheet->setCellValueExplicit($cell, (float) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(match ($col->type) {
                    'money' => '#,##0.00',
                    'rate' => '0.0000####',
                    default => floor((float) $value) == (float) $value ? '#,##0' : '#,##0.00',
                });
                break;
            case 'date':
            case 'datetime':
                $date = $value instanceof \DateTimeInterface ? $value : new \DateTime((string) $value);
                $sheet->setCellValue($cell, ExcelDate::PHPToExcel($date));
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($col->type === 'date' ? 'dd.mm.yyyy' : 'dd.mm.yyyy hh:mm');
                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                break;
            default:
                $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }
}
