<x-layouts.app :title="$class::title()">
    @php
        $columns = $report->columns();
        $filters = $report->filters();
        $chart = $report->chart();
        $numeric = fn ($c) => in_array($c->type, ['money', 'number', 'rate']);
        $totals = [];
        foreach ($columns as $i => $c) { if ($c->total) { $totals[$i] = $rows->sum(fn ($r) => (float) $c->read($r)); } }
        $toneCls = ['success' => 'text-success', 'danger' => 'text-danger'];
    @endphp
    <x-page-header :title="$class::title()" :subtitle="$class::description()" :icon="$class::icon()" :back="route('reports.index')">
        <x-slot:actions>
            @can('reports.export')
                <a href="{{ route('reports.export', [$class::key()] + request()->query() + ['format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
                <a href="{{ route('reports.export', [$class::key()] + request()->query() + ['format' => 'pdf']) }}" class="btn btn-secondary"><x-icon name="file-pdf" class="size-4 text-danger"/> PDF</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if($filters)
        <form method="GET" class="card p-4 mb-6 flex flex-wrap items-end gap-3">
            @foreach($filters as $key => $def)
                <label class="flex flex-col gap-1 text-xs text-muted">
                    {{ $def['label'] }}
                    @if($def['type'] === 'select')
                        <select name="{{ $key }}" class="input !h-9 min-w-[180px] text-[13px]">
                            <option value="">— hamısı —</option>
                            @foreach($def['options'] as $v => $t)<option value="{{ $v }}" @selected((string) request($key) === (string) $v)>{{ $t }}</option>@endforeach
                        </select>
                    @else
                        <input type="date" name="{{ $key }}" value="{{ request($key, $key === 'from' ? $report->from()->format('Y-m-d') : ($key === 'to' ? $report->to()->format('Y-m-d') : '')) }}" class="input !h-9 !w-[160px] font-mono text-[13px]">
                    @endif
                </label>
            @endforeach
            <button class="btn btn-primary btn-sm h-9"><x-icon name="refresh" class="size-4"/> Hesabla</button>
            @if(request()->query())<a href="{{ url()->current() }}" class="btn btn-ghost btn-sm h-9">Sıfırla</a>@endif
            <div class="ml-auto flex flex-wrap gap-1.5 text-xs">
                @foreach(['Bu ay' => [now()->startOfMonth(), now()], 'Keçən ay' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()], 'Bu rüb' => [now()->firstOfQuarter(), now()], 'Bu il' => [now()->startOfYear(), now()]] as $l => [$f, $t])
                    @if(isset($filters['from']))
                        <a href="{{ request()->fullUrlWithQuery(['from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d')]) }}" class="btn btn-ghost btn-sm !h-7 text-muted">{{ $l }}</a>
                    @endif
                @endforeach
            </div>
        </form>
    @endif

    @if($summary = $report->summary())
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
            @foreach($summary as $s)
                <div class="card p-5" style="--i:{{ $loop->index }}">
                    <div class="text-xs text-muted">{{ $s['label'] }}</div>
                    <div class="mt-1 text-2xl font-semibold font-mono tabular {{ $toneCls[$s['tone'] ?? ''] ?? '' }}">
                        @if(!empty($s['money']))
                            <span x-data x-countup="{{ (float) $s['value'] }}" data-decimals="2" data-suffix=" {{ currency_symbol($s['currency'] ?? 'AZN') }}">{{ money($s['value'], $s['currency'] ?? 'AZN') }}</span>
                        @else
                            {{ is_float($s['value']) ? num($s['value'], 1) : $s['value'] }}{{ $s['suffix'] ?? '' }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($chart)
        <section class="card p-5 lg:p-6 mb-6">
            <div class="-mx-2" style="height: {{ $chart['height'] ?? 300 }}px" x-data x-chart="{{ json_encode($chart) }}"><div class="skeleton h-full mx-2"></div></div>
        </section>
    @endif

    @if($note = $report->note())
        <p class="mb-4 text-sm text-muted flex gap-2"><x-icon name="info" class="size-4 shrink-0 mt-0.5"/> {{ $note }}</p>
    @endif

    <section class="card overflow-hidden">
        @if($rows->isEmpty())
            <x-empty icon="chart" title="Seçilmiş dövr üçün məlumat yoxdur" text="Tarix aralığını və ya filtrləri dəyişin."/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr>@foreach($columns as $c)<th @class(['!text-right' => $numeric($c)])>{{ $c->label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr>
                            @foreach($columns as $c)
                                @php $v = $c->read($row); @endphp
                                <td data-label="{{ $c->label }}" @class(['num' => $numeric($c), 'text-danger' => $c->type === 'money' && is_numeric($v) && $v < 0])>{{ $c->display($v) ?: '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                    @if($totals)
                        <tfoot class="hidden md:table-footer-group">
                        <tr class="bg-surface-2 font-semibold">
                            @foreach($columns as $i => $c)
                                <td class="px-4 py-3 border-t-2 border-line-strong {{ isset($totals[$i]) ? 'text-right font-mono' : '' }}">{{ $i === 0 ? 'Cəmi' : (isset($totals[$i]) ? $c->display($totals[$i]) : '') }}</td>
                            @endforeach
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <div class="px-4 py-3 border-t border-line text-xs text-muted">{{ $rows->count() }} sətir · {{ implode(' · ', $report->filterSummary()) }}</div>
        @endif
    </section>
</x-layouts.app>
