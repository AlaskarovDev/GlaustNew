<?php

namespace App\Reports;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Project;
use App\Tables\Column;
use Illuminate\Support\Collection;

class IncomeExpenseReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'income-expense';
    }

    public static function title(): string
    {
        return __('Gəlir və xərc');
    }

    public static function description(): string
    {
        return __('Aylar və kateqoriyalar üzrə daxilolma və məxaric, AZN-ə çevrilmiş (daxili köçürmələr çıxılır).');
    }

    public static function icon(): string
    {
        return 'trending-up';
    }

    public static function ability(): string
    {
        return 'bank.view';
    }

    public function filters(): array
    {
        return parent::filters() + [
            'project_id' => ['label' => __('Layihə'), 'type' => 'select', 'options' => Project::orderBy('code')->pluck('code', 'id')->all()],
            'group' => ['label' => __('Qruplaşdır'), 'type' => 'select', 'options' => ['month' => __('Aylar üzrə'), 'category' => __('Kateqoriyalar üzrə')]],
        ];
    }

    private function base()
    {
        return BankTransaction::query()->where('kind', 'regular')
            ->whereBetween('transaction_date', [$this->from()->toDateString(), $this->to()->toDateString()])
            ->when($this->request->filled('project_id'), fn ($q) => $q->where('project_id', $this->request->integer('project_id')));
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        if ($this->request->query('group') === 'category') {
            $cats = Category::pluck('name', 'id');
            $rows = $this->base()->selectRaw('category_id, direction, SUM(amount_azn) as s')->groupBy('category_id', 'direction')->get()
                ->groupBy('category_id')
                ->map(fn ($g, $id) => ['label' => $cats[$id] ?? __('Kateqoriyasız'), 'in' => (float) $g->where('direction', 'in')->sum('s'), 'out' => (float) $g->where('direction', 'out')->sum('s')])
                ->sortByDesc(fn ($r) => $r['in'] + $r['out'])->values();
        } else {
            $expr = $this->monthExpr('transaction_date');
            $raw = $this->base()->selectRaw("{$expr} as ym, direction, SUM(amount_azn) as s")->groupBy('ym', 'direction')->get()->groupBy('ym');
            $rows = collect($this->months())->map(fn ($label, $ym) => [
                'label' => $label,
                'in' => (float) ($raw[$ym] ?? collect())->where('direction', 'in')->sum('s'),
                'out' => (float) ($raw[$ym] ?? collect())->where('direction', 'out')->sum('s'),
            ])->values();
        }

        return $this->data = $rows->map(fn ($r) => $r + ['net' => round($r['in'] - $r['out'], 2)]);
    }

    public function columns(): array
    {
        return [
            Column::make($this->request->query('group') === 'category' ? __('Kateqoriya') : 'Ay', 'label'),
            Column::make(__('Daxilolma (AZN)'), 'in', 'money', total: true),
            Column::make(__('Məxaric (AZN)'), 'out', 'money', total: true),
            Column::make(__('Fərq (AZN)'), 'net', 'money', total: true),
        ];
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $in = $this->data()->sum('in');
        $out = $this->data()->sum('out');

        return [
            ['label' => __('Daxilolma'), 'value' => $in, 'money' => true, 'tone' => 'success'],
            ['label' => __('Məxaric'), 'value' => $out, 'money' => true, 'tone' => 'danger'],
            ['label' => __('Nəticə'), 'value' => $in - $out, 'money' => true, 'tone' => $in - $out >= 0 ? 'success' : 'danger'],
            ['label' => 'Rentabellik', 'value' => $in > 0 ? round(($in - $out) / $in * 100, 1) : 0, 'suffix' => '%'],
        ];
    }

    public function chart(): ?array
    {
        $d = $this->data();

        return [
            'type' => 'bar', 'height' => 320, 'money' => true, 'categories' => $d->pluck('label')->all(),
            'colors' => ['#0f9d8a', '#e5484d'], 'horizontal' => $this->request->query('group') === 'category',
            'series' => [['name' => __('Daxilolma'), 'data' => $d->pluck('in')->all()], ['name' => __('Məxaric'), 'data' => $d->pluck('out')->all()]],
        ];
    }
}
