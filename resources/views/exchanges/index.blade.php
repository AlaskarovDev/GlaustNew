<x-layouts.app :title="__('Birja')">
    <x-page-header :title="__('Birja')" icon="trending-up"
                   subtitle="{{ __('Valyutaların ABŞ dollarına nisbətən kursları — birja və ya mərkəzi bank seçin') }}"/>

    {{-- the markets and banks --}}
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3 mb-6" role="list" aria-label="{{ __('Mənbələr') }}">
        @foreach($sources as $s)
            @php $on = $s->key() === $source->key(); @endphp
            <a role="listitem" href="{{ route('exchanges.index', ['source' => $s->key(), 'days' => $days]) }}" @if($on) aria-current="true" @endif
               @class(['card p-4 flex items-start gap-3 transition-colors', 'border-brand ring-2 ring-brand/20' => $on, 'hover:border-line-strong' => ! $on])>
                <span @class(['grid place-items-center size-10 rounded-xl shrink-0', 'bg-brand-soft text-brand' => $s->kind() === 'birja', 'bg-surface-2 text-ink-2' => $s->kind() !== 'birja'])>
                    <x-icon :name="$s->kind() === 'birja' ? 'trending-up' : 'bank'" class="size-5"/>
                </span>
                <span class="min-w-0">
                    <span class="flex items-center gap-2">
                        <span class="font-semibold text-ink truncate">{{ $s->name() }}</span>
                        <span @class(['badge !text-[10px]', 'badge-teal' => $s->kind() === 'birja', 'badge-slate' => $s->kind() !== 'birja'])>{{ $s->kind() === 'birja' ? __('Birja') : __('Mərkəzi bank') }}</span>
                    </span>
                    <span class="block text-xs text-muted">{{ $s->country() }}</span>
                </span>
                @if($on)<x-icon name="check-circle" class="size-5 text-brand ml-auto shrink-0"/>@endif
            </a>
        @endforeach
    </div>

    @if($error)
        <div class="card border-saffron/40 bg-saffron-soft/60 p-4 mb-6 flex gap-3 text-sm" role="alert">
            <x-icon name="alert" class="size-5 text-saffron shrink-0"/><div>{{ $error }}</div>
        </div>
    @endif

    @if($rates)
        <div class="grid xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] gap-6 items-start">
            {{-- Chart --}}
            <section class="card p-5 lg:p-6 xl:sticky xl:top-24 order-last xl:order-first">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <form method="GET" action="{{ route('exchanges.index') }}" class="flex items-center gap-2">
                            <input type="hidden" name="source" value="{{ $source->key() }}"><input type="hidden" name="days" value="{{ $days }}">
                            <h2 class="text-base font-semibold">USD /</h2>
                            <select name="code" class="input !h-8 !w-auto !py-0 font-semibold" x-data @change="$el.form.submit()" aria-label="{{ __('Valyuta') }}">
                                @foreach(array_keys($rates) as $c)<option value="{{ $c }}" @selected($c === $code)>{{ $c }}</option>@endforeach
                            </select>
                        </form>
                        <p class="text-xs text-muted">{{ __('1 USD neçə :v1 edir · :v2', ['v1' => $code, 'v2' => $source->name()]) }}</p>
                    </div>
                    <div class="flex gap-1 p-1 rounded-lg bg-surface-2 border border-line">
                        @foreach([30 => __('30 gün'), 90 => __('90 gün'), 365 => __('1 il')] as $d => $l)
                            <a href="{{ route('exchanges.index', ['source' => $source->key(), 'code' => $code, 'days' => $d]) }}"
                               @class(['px-2.5 h-7 grid place-items-center rounded-md text-xs font-medium', 'bg-surface shadow-sm text-ink' => $days === $d, 'text-muted hover:text-ink' => $days !== $d])>{{ $l }}</a>
                        @endforeach
                    </div>
                </div>
                @if(count($history) > 1)
                    @php
                        $vals = array_values($history);
                        $chart = [
                            'type' => 'area', 'height' => 320, 'rate' => true, 'colors' => ['#0f9d8a'],
                            'series' => [['name' => 'USD/'.$code, 'data' => array_map(fn ($v) => round($v, 6), $vals)]],
                            'categories' => array_map(fn ($d) => \Carbon\Carbon::parse($d)->format('d.m'), array_keys($history)),
                            'yaxis' => ['labels' => ['style' => ['fontSize' => '12px']], 'decimalsInFloat' => 4, 'tickAmount' => 5],
                            'xaxis' => ['tickAmount' => 8, 'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true]],
                        ];
                        $first = $vals[0];
                        $periodPct = $first ? (end($vals) - $first) / $first * 100 : 0;
                    @endphp
                    <div class="mt-4 -mx-2 h-[320px]" x-chart="{{ json_encode($chart) }}"><div class="skeleton h-full mx-2"></div></div>
                    <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                        <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Minimum') }}</dt><dd class="font-mono font-semibold">{{ rate_fmt(round(min($vals), 4)) }}</dd></div>
                        <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Orta') }}</dt><dd class="font-mono font-semibold">{{ rate_fmt(round(array_sum($vals) / count($vals), 4)) }}</dd></div>
                        <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Maksimum') }}</dt><dd class="font-mono font-semibold">{{ rate_fmt(round(max($vals), 4)) }}</dd></div>
                        <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Dövr üzrə') }}</dt>
                            <dd class="font-mono font-semibold {{ $periodPct > 0.005 ? 'text-success' : ($periodPct < -0.005 ? 'text-danger' : 'text-faint') }}">{{ $periodPct > 0.005 ? '▲ +' : ($periodPct < -0.005 ? '▼ ' : '') }}{{ num($periodPct) }}%</dd></div>
                    </dl>
                @else
                    <x-empty icon="chart" :title="__('Tarixçə yoxdur')" :text="$historyError ?? __('Bu mənbə seçilmiş valyuta üçün tarixçə vermir.')"/>
                @endif
                <p class="mt-4 text-xs text-muted flex items-start gap-1.5"><x-icon name="info" class="size-4 shrink-0"/> {{ $source->note() }} · <a href="{{ $source->site() }}" target="_blank" rel="noopener" class="text-brand-ink underline">{{ parse_url($source->site(), PHP_URL_HOST) }}</a></p>
            </section>

            {{-- Table --}}
            <section class="card overflow-hidden">
                <header class="px-5 py-4 border-b border-line flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold">{{ $source->name() }}</h2>
                        <p class="text-xs text-muted">{{ __('Hazırkı kurs') }}{{ $date ? ' · '.\Carbon\Carbon::parse($date)->format('d.m.Y') : '' }} · {{ count($rates) }} {{ __('valyuta') }}</p>
                    </div>
                </header>
                <table class="table-g table-stack text-sm">
                    <thead><tr><th>{{ __('Valyuta') }}</th><th class="!text-right">{{ __('1 USD =') }}</th><th class="!text-right">{{ __('1 vahid = USD') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($rates as $c => $v)
                        <tr @class(['bg-brand-soft/40' => $c === $code])>
                            <td data-label="{{ __('Valyuta') }}" class="font-semibold text-ink">{{ $c }}</td>
                            <td data-label="{{ __('1 USD =') }}" class="num font-medium">{{ rate_fmt(round($v, $v >= 100 ? 2 : 4)) }} <span class="text-faint text-xs">{{ $c }}</span></td>
                            <td data-label="{{ __('1 vahid = USD') }}" class="num text-ink-2">{{ $v > 0 ? rate_fmt(round(1 / $v, 1 / $v < 0.01 ? 6 : 4)) : '—' }}</td>
                            <td class="text-right">
                                <a href="{{ route('exchanges.index', ['source' => $source->key(), 'code' => $c, 'days' => $days]) }}" class="btn btn-ghost btn-sm" aria-label="{{ __('Qrafik') }}: USD/{{ $c }}">
                                    <x-icon name="chart" class="size-4"/><span class="hidden sm:inline">{{ __('Qrafik') }}</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        </div>
    @endif
</x-layouts.app>
