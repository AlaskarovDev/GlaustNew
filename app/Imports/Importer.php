<?php

namespace App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * One import type. A row arrives as [field => raw cell value] after column mapping;
 * the importer normalises, validates and persists it, or throws RowError.
 */
abstract class Importer
{
    abstract public static function type(): string;

    abstract public static function title(): string;

    abstract public static function ability(): string;

    /** field => ['label' => .., 'required' => bool, 'aliases' => [...], 'example' => ..] */
    abstract public function fields(): array;

    /** Persist one normalised row. @throws RowError */
    abstract protected function persist(array $row): void;

    public static function description(): string
    {
        return '';
    }

    public function import(array $raw): void
    {
        $this->persist($this->normalise($raw));
    }

    /** Dry run used by the preview: returns the error message or null. */
    public function check(array $raw): ?string
    {
        try {
            $this->validateRow($this->normalise($raw));

            return null;
        } catch (RowError $e) {
            return $e->getMessage();
        }
    }

    /** Validation-only part of persist(); importers override when they look things up. */
    protected function validateRow(array $row): void
    {
        $this->validate($row, $this->rules());
    }

    protected function rules(): array
    {
        return [];
    }

    protected function normalise(array $raw): array
    {
        return array_map(fn ($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : $v, $raw);
    }

    /** @throws RowError */
    protected function validate(array $row, array $rules, array $attributes = []): array
    {
        $labels = array_map(fn ($f) => $f['label'], $this->fields());
        try {
            return Validator::make($row, $rules, [], $attributes + $labels)->validate();
        } catch (ValidationException $e) {
            throw new RowError(implode(' ', array_map(fn ($m) => $m[0], $e->errors())));
        }
    }

    /** Auto-map: header text -> field, by label or alias, case/diacritic-insensitive. */
    public function guessMapping(array $headers): array
    {
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtr(az_lower((string) $s), ['ə' => 'e', 'ö' => 'o', 'ü' => 'u', 'ğ' => 'g', 'ı' => 'i', 'ş' => 's', 'ç' => 'c']));
        $map = [];
        foreach ($this->fields() as $key => $def) {
            $candidates = array_map($norm, array_merge([$key, $def['label']], $def['aliases'] ?? []));
            foreach ($headers as $index => $header) {
                if ($header !== null && $header !== '' && in_array($norm($header), $candidates, true) && ! in_array($index, $map, true)) {
                    $map[$key] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.y', 'd-m-Y', 'Y.m.d'] as $format) {
            $d = \DateTime::createFromFormat('!'.$format, trim((string) $value));
            if ($d && $d->format($format) === trim((string) $value)) {
                return $d->format('Y-m-d');
            }
        }
        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return '__invalid__';
        }
    }

    /** Map a human label ("Müştəri") or key ("customer") to a key from $options. */
    public static function option(mixed $value, array $options, ?string $default = null, array $aliases = []): ?string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $v = az_lower(trim((string) $value));
        foreach ($aliases as $alias => $key) {
            if ($v === az_lower($alias)) {
                return $key;
            }
        }
        foreach ($options as $key => $label) {
            if ($v === az_lower((string) $key) || $v === az_lower((string) $label) || str_starts_with(az_lower((string) $label), $v)) {
                return (string) $key;
            }
        }

        return '__invalid__';
    }
}
