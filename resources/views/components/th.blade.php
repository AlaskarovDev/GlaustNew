@props(['table' => null, 'sort' => null, 'num' => false])
<th {{ $attributes->class(['!text-right' => $num]) }} @if($table && $sort && $table->sortState($sort)) aria-sort="{{ $table->sortState($sort) === 'asc' ? 'ascending' : 'descending' }}" @endif>
    @if($table && $sort)
        @php $state = $table->sortState($sort); @endphp
        <a href="{{ $table->sortUrl($sort) }}" @class(['inline-flex items-center gap-1 hover:text-ink transition-colors', 'text-ink' => $state, 'flex-row-reverse' => $num])>
            {{ $slot }}
            <x-icon :name="$state === 'asc' ? 'chevron-up' : 'chevron-down'" @class(['size-3.5', 'opacity-30' => ! $state])/>
        </a>
    @else
        {{ $slot }}
    @endif
</th>
