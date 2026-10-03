<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An Excel/CSV upload, judged by its extension and its first bytes — not by `mimes:`.
 * `mimes:` asks the server's libmagic, which reports workbooks re-saved by Excel, WPS or
 * LibreOffice as application/zip or octet-stream, so a correctly filled template was refused.
 * Whether the content really is a workbook is decided by PhpSpreadsheet when it is read.
 */
class SpreadsheetFile implements ValidationRule
{
    /** @param  list<string>  $extensions */
    public function __construct(private array $extensions = ['xlsx', 'xls', 'csv']) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail(':attribute yüklənmədi. Yenidən cəhd edin.');

            return;
        }
        $ext = strtolower($value->getClientOriginalExtension());
        if (! in_array($ext, $this->extensions, true)) {
            $fail(':attribute bu növlərdən biri olmalıdır: '.implode(', ', $this->extensions).'.');

            return;
        }

        $head = (string) @file_get_contents($value->getRealPath(), false, null, 0, 8);
        $ok = match ($ext) {
            'xlsx' => str_starts_with($head, "PK\x03\x04"),
            'xls' => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") || str_starts_with($head, "PK\x03\x04"),
            default => $head !== '' && ! str_starts_with($head, "PK\x03\x04") && ! str_contains($head, "\0"),
        };
        if (! $ok) {
            $fail(':attribute .'.$ext.' faylı deyil (məzmunu uyğun gəlmir). Faylı Excel-də açıb «.xlsx» kimi yenidən yadda saxlayın.');
        }
    }
}
