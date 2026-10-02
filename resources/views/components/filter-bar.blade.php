@props(['table', 'exportRoute' => null, 'exportAbility' => null, 'placeholder' => 'Axtar…'])
@php
    $filters = $table->filters();
    $active = $table->hasActiveFilters();
    $canExport = $exportRoute && (! $exportAbility || auth()->user()->can($exportAbility));
    $query = request()->except(['page']);
@endphp
<form method="GET" class="flex flex-col lg:flex-row lg:items-center gap-3 p-4 border-b border-line" x-data="{ more: {{ $active && count($filters) > 2 ? 'true' : 'false' }} }">
    @foreach(request()->only(['sort', 'dir', 'per_page', 'view']) as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
    <div class="relative flex-1 min-w-0 lg:max-w-sm">
        <x-icon name="search" class="size-4 text-faint absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"/>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $placeholder }}" class="input pl-9" aria-label="Axtarış">
    </div>
    <div class="flex flex-wrap items-center gap-2">
        @foreach($filters as $key => $def)
            @if($def['type'] === 'select')
                <select name="{{ $key }}" class="input !h-9 !w-auto min-w-[140px] text-[13px]" aria-label="{{ $def['label'] }}" @change="$el.form.requestSubmit()">
                    <option value="">{{ $def['label'] }}: hamısı</option>
                    @foreach($def['options'] as $val => $text)
                        <option value="{{ $val }}" @selected((string) request($key) === (string) $val)>{{ $text }}</option>
                    @endforeach
                </select>
            @elseif($def['type'] === 'date')
                <label class="flex items-center gap-1.5 text-xs text-muted">
                    <span class="whitespace-nowrap">{{ $def['label'] }}</span>
                    <input type="date" name="{{ $key }}" value="{{ request($key) }}" class="input !h-9 !w-[150px] text-[13px] font-mono" @change="$el.form.requestSubmit()">
                </label>
            @endif
        @endforeach
        <button class="btn btn-secondary btn-sm h-9"><x-icon name="filter" class="size-4"/> Tətbiq et</button>
        @if($active)
            <a href="{{ url()->current() }}" class="btn btn-ghost btn-sm h-9 text-muted"><x-icon name="x" class="size-4"/> Sıfırla</a>
        @endif
    </div>
    @if($canExport)
        <div class="lg:ml-auto flex items-center gap-2">
            <a href="{{ route($exportRoute, array_merge($query, ['format' => 'xlsx'])) }}" class="btn btn-secondary btn-sm h-9" title="Cari filtrlərlə Excel-ə export">
                <x-icon name="sheet" class="size-4 text-success"/> Excel
            </a>
            <a href="{{ route($exportRoute, array_merge($query, ['format' => 'pdf'])) }}" class="btn btn-secondary btn-sm h-9" title="Cari filtrlərlə PDF-ə export">
                <x-icon name="file-pdf" class="size-4 text-danger"/> PDF
            </a>
        </div>
    @endif
</form>
