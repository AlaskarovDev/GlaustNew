<?php

namespace App\Support\Export;

use App\Tables\Column;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/** Tabular PDF: company header, filters, page numbers. DejaVu Sans covers ə, ğ, ı, ş. */
class PdfExporter
{
    private const MAX_ROWS = 3000;

    /** @param Column[] $columns */
    public function download(string $title, array $columns, iterable $rows, string $filename, array $filters = []): Response
    {
        $data = [];
        $totals = [];
        $truncated = false;
        foreach ($rows as $row) {
            if (count($data) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $line = [];
            foreach ($columns as $i => $col) {
                $value = $col->read($row);
                $line[] = $col->display($value);
                if ($col->total) {
                    $totals[$i] = ($totals[$i] ?? 0) + (float) $value;
                }
            }
            $data[] = $line;
        }

        return $this->render('exports.table', [
            'title' => $title,
            'columns' => $columns,
            'rows' => $data,
            'totals' => $totals,
            'filters' => $filters,
            'truncated' => $truncated,
            'maxRows' => self::MAX_ROWS,
        ], $filename, count($columns) > 6);
    }

    public function render(string $view, array $data, string $filename, bool $landscape = false, bool $inline = false): Response
    {
        $pdf = Pdf::loadView($view, $data + ['company' => tenant()])
            ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => true, 'isFontSubsettingEnabled' => true, 'dpi' => 96]);

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }
}
