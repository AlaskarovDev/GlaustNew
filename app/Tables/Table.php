<?php

namespace App\Tables;

use App\Support\Export\PdfExporter;
use App\Support\Export\SpreadsheetExporter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A module list: one query with search, filters and sorting, shared by the
 * screen (paginate) and by Excel / PDF export (same filters, all rows).
 */
abstract class Table
{
    /** Columns searched by ?q= (supports "relation.column"). */
    protected array $searchable = [];

    /** ?sort=key => SQL column. First entry is the default. */
    protected array $sortable = [];

    protected string $defaultDirection = 'desc';

    public function __construct(protected Request $request) {}

    abstract public function title(): string;

    abstract protected function baseQuery(): Builder;

    /** @return Column[] */
    abstract public function columns(): array;

    /** Filter definitions for the filter bar: [key => [label, type(select|date|text), options?]]. */
    public function filters(): array
    {
        return [];
    }

    protected function applyFilters(Builder $query): void {}

    public function query(): Builder
    {
        $query = $this->baseQuery();

        $term = trim((string) $this->request->query('q'));
        if ($term !== '' && $this->searchable) {
            $query->where(function (Builder $w) use ($term) {
                foreach ($this->searchable as $column) {
                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column, 2);
                        $w->orWhereHas($relation, fn ($r) => $r->where($field, 'like', '%'.$term.'%'));
                    } else {
                        $w->orWhere($w->qualifyColumn($column), 'like', '%'.$term.'%');
                    }
                }
            });
        }

        $this->applyFilters($query);

        if ($this->sortable) {
            $key = (string) $this->request->query('sort', array_key_first($this->sortable));
            $column = $this->sortable[$key] ?? reset($this->sortable);
            $dir = $this->request->query('dir') === 'asc' ? 'asc' : ($this->request->query('dir') === 'desc' ? 'desc' : $this->defaultDirection);
            $query->orderBy($column, $dir)->orderBy($query->qualifyColumn('id'), 'desc');
        }

        return $query;
    }

    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $perPage = in_array((int) $this->request->query('per_page'), [10, 25, 50, 100], true) ? (int) $this->request->query('per_page') : $perPage;

        return $this->query()->paginate($perPage)->withQueryString();
    }

    /** Human description of the active filters, printed on exports. */
    public function filterSummary(): array
    {
        $out = [];
        if ($q = trim((string) $this->request->query('q'))) {
            $out[] = __('Axtarış: ').$q;
        }
        foreach ($this->filters() as $key => $def) {
            $value = $this->request->query($key);
            if ($value === null || $value === '') {
                continue;
            }
            $shown = isset($def['options']) ? ($def['options'][$value] ?? $value) : ($def['type'] === 'date' ? azdate($value) : $value);
            $out[] = $def['label'].': '.$shown;
        }

        return $out;
    }

    public function export(string $format): Response
    {
        $name = Str::slug(strtr($this->title(), ['ə' => 'e', 'Ə' => 'E'])).'-'.now()->format('Y-m-d');

        return $format === 'pdf'
            ? app(PdfExporter::class)->download($this->title(), $this->columns(), $this->query()->lazy(500), $name.'.pdf', $this->filterSummary())
            : app(SpreadsheetExporter::class)->download($this->title(), $this->columns(), $this->query()->lazy(500), $name.'.xlsx', $this->filterSummary());
    }

    /** Sort link helper for table headers. */
    public function sortUrl(string $key): string
    {
        $current = $this->request->query('sort', array_key_first($this->sortable));
        $dir = $current === $key && $this->request->query('dir', $this->defaultDirection) === 'desc' ? 'asc' : 'desc';

        return $this->request->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null]);
    }

    public function sortState(string $key): ?string
    {
        $current = $this->request->query('sort', array_key_first($this->sortable));

        return $current === $key ? $this->request->query('dir', $this->defaultDirection) : null;
    }

    public function hasActiveFilters(): bool
    {
        return $this->filterSummary() !== [];
    }
}
