<?php

namespace App\Imports;

use App\Models\BankAccount;
use App\Models\Import;
use App\Support\Export\SpreadsheetExporter;
use App\Tables\Column;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Reads an uploaded sheet, applies the mapping, imports row by row and writes the error file. */
class ImportRunner
{
    public const TYPES = [
        CounterpartyImporter::class,
        ProjectImporter::class,
        TaskImporter::class,
        BankTransactionImporter::class,
        ShipmentImporter::class,
    ];

    public const SYNC_LIMIT = 300;

    public static function importerFor(Import $import): Importer
    {
        $class = collect(self::TYPES)->first(fn ($c) => $c::type() === $import->type) ?? throw new \InvalidArgumentException('Unknown import type');
        if ($class === BankTransactionImporter::class) {
            return new BankTransactionImporter(BankAccount::find($import->mapping['__account'] ?? 0));
        }

        return new $class;
    }

    /** @return array{headers: array, rows: array<int, array>} rows keyed by sheet line number */
    public static function read(string $absolutePath, int $limit = 0): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($absolutePath)->getActiveSheet();

        // toArray keeps every column position (a cell iterator would skip empty cells and shift columns).
        $headers = null;
        $rows = [];
        foreach ($sheet->toArray(null, true, false, false) as $i => $cells) {
            if (! array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(fn ($h) => is_string($h) ? trim($h) : $h, $cells);

                continue;
            }
            $rows[$i + 1] = $cells;
            if ($limit && count($rows) >= $limit) {
                break;
            }
        }

        return ['headers' => $headers ?? [], 'rows' => $rows];
    }

    public static function mapRow(array $cells, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $field => $index) {
            if (str_starts_with($field, '__') || $index === null || $index === '') {
                continue;
            }
            $out[$field] = $cells[(int) $index] ?? null;
        }

        return $out;
    }

    public function run(Import $import): void
    {
        $import->update(['status' => 'processing', 'message' => null]);
        $importer = self::importerFor($import);
        $data = self::read(Storage::disk('local')->path($import->path));

        $ok = 0;
        $failed = [];
        foreach ($data['rows'] as $line => $cells) {
            try {
                DB::transaction(fn () => $importer->import(self::mapRow($cells, $import->mapping ?? [])));
                $ok++;
            } catch (RowError $e) {
                $failed[] = ['line' => $line, 'cells' => $cells, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                report($e);
                $failed[] = ['line' => $line, 'cells' => $cells, 'error' => __('Gözlənilməz xəta: ').class_basename($e)];
            }
        }

        $errorPath = $failed ? $this->writeErrors($import, $data['headers'], $failed) : null;
        $import->update([
            'status' => 'done', 'total_rows' => count($data['rows']), 'imported_rows' => $ok,
            'failed_rows' => count($failed), 'error_path' => $errorPath,
        ]);
    }

    private function writeErrors(Import $import, array $headers, array $failed): string
    {
        $columns = [Column::make('Sətir', 'line', 'number'), Column::make('Xəta', 'error', width: 50)];
        foreach ($headers as $i => $h) {
            $columns[] = Column::make((string) ($h ?: 'Sütun '.($i + 1)), fn ($r) => $r['cells'][$i] ?? null);
        }
        $book = app(SpreadsheetExporter::class)->build('Import xətaları — '.$import->original_name, $columns, $failed);
        $path = 'imports/'.$import->company_id.'/errors-'.$import->id.'.xlsx';
        Storage::disk('local')->makeDirectory(dirname($path));
        (new Xlsx($book))->save(Storage::disk('local')->path($path));

        return $path;
    }

    /** Downloadable template: header row + one example row. */
    public static function template(Importer $importer): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Import');
        $col = 1;
        foreach ($importer->fields() as $def) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue($letter.'1', $def['label']);
            $sheet->setCellValueExplicit($letter.'2', (string) ($def['example'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getStyle($letter.'1')->getFont()->setBold(true)->getColor()->setRGB(! empty($def['required']) ? 'B42318' : '1D2433');
            $sheet->getColumnDimension($letter)->setAutoSize(true);
            $col++;
        }
        $sheet->freezePane('A2');

        return $book;
    }
}
