<?php

namespace App\Tables;

/** One export column: how to read a value from a row and how to format it. */
class Column
{
    public function __construct(
        public string $label,
        public \Closure $value,
        public string $type = 'text', // text | money | number | rate | date | datetime
        public ?float $width = null,
        public bool $total = false,
    ) {}

    public static function make(string $label, \Closure|string $value, string $type = 'text', ?float $width = null, bool $total = false): self
    {
        $reader = is_string($value) ? fn ($row) => data_get($row, $value) : $value;

        return new self($label, $reader, $type, $width, $total);
    }

    public function read(mixed $row): mixed
    {
        return ($this->value)($row);
    }

    /** Display string for PDF. */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            'money' => num($value),
            'number' => num($value, is_float($value + 0) && floor((float) $value) != (float) $value ? 2 : 0),
            'rate' => rate_fmt($value),
            'date' => azdate($value),
            'datetime' => azdate($value, true),
            default => (string) $value,
        };
    }
}
