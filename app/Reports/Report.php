<?php

namespace App\Reports;

use App\Tables\Column;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A report: filters -> summary tiles, an optional chart, and a table that the
 * screen, Excel and PDF all render from the same Column definitions.
 */
abstract class Report
{
    public function __construct(protected Request $request) {}

    abstract public static function key(): string;

    abstract public static function title(): string;

    abstract public static function description(): string;

    public static function icon(): string
    {
        return 'chart';
    }

    public static function ability(): string
    {
        return 'reports.view';
    }

    /** Same shape as Table::filters(). */
    public function filters(): array
    {
        return [
            'from' => ['label' => __('Tarixdən'), 'type' => 'date'],
            'to' => ['label' => __('Tarixədək'), 'type' => 'date'],
        ];
    }

    /** @return Column[] */
    abstract public function columns(): array;

    abstract public function rows(): iterable;

    /** [['label' => .., 'value' => .., 'money' => bool, 'tone' => 'success'|'danger'|null]] */
    public function summary(): array
    {
        return [];
    }

    /** x-chart config or null. */
    public function chart(): ?array
    {
        return null;
    }

    public function note(): ?string
    {
        return null;
    }

    public function from(): CarbonImmutable
    {
        return $this->request->filled('from')
            ? CarbonImmutable::parse($this->request->query('from'))->startOfDay()
            : CarbonImmutable::today()->startOfYear();
    }

    public function to(): CarbonImmutable
    {
        return $this->request->filled('to')
            ? CarbonImmutable::parse($this->request->query('to'))->startOfDay()
            : CarbonImmutable::today();
    }

    public function periodLabel(): string
    {
        return $this->from()->format('d.m.Y').' — '.$this->to()->format('d.m.Y');
    }

    public function filterSummary(): array
    {
        $out = [__('Dövr: ').$this->periodLabel()];
        foreach ($this->filters() as $key => $def) {
            if (in_array($key, ['from', 'to'], true) || ! $this->request->filled($key)) {
                continue;
            }
            $out[] = $def['label'].': '.($def['options'][$this->request->query($key)] ?? $this->request->query($key));
        }

        return $out;
    }

    protected function monthExpr(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', {$column})" : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /** @return array<string, string> Y-m => label for every month in the period */
    protected function months(): array
    {
        $out = [];
        for ($m = $this->from()->startOfMonth(); $m->lte($this->to()); $m = $m->addMonth()) {
            $out[$m->format('Y-m')] = az_month($m->month, true)." '".$m->format('y');
        }

        return $out;
    }
}
