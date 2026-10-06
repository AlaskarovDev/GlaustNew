<x-layouts.app :title="__('Valyuta məzənnələri')">
    <x-page-header :title="__('Valyuta məzənnələri')" icon="coins"
                   subtitle="{{ __('Azərbaycan Respublikasının Mərkəzi Bankı (CBAR) · rəsmi məzənnələr · :v1', ['v1' => $date->format('d.m.Y')]) }}">
        <x-slot:actions>
            <form method="GET" class="flex items-center gap-2">
                <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" max="{{ $today->format('Y-m-d') }}" class="input !w-[160px] font-mono" aria-label="{{ __('Tarix') }}">
                <input type="hidden" name="code" value="{{ $code }}">
                <button class="btn btn-primary">{{ __('Göstər') }}</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if($bulletin && ! $bulletin->isSameDay($date))
        <div class="card bg-surface-2 p-4 mb-5 flex gap-3 text-sm">
            <x-icon name="info" class="size-5 text-brand shrink-0"/>
            <div>{{ $date->format('d.m.Y') }} {{ __('tarixində qüvvədə olan məzənnələr Mərkəzi Bankın') }} <b>{{ $bulletin->format('d.m.Y') }}</b> {{ __('tarixli bülletenindəndir
                (') }}{{ $date->isToday() ? __('bugünkü bülleten hələ dərc olunmayıb') : __('həmin gün yeni bülleten dərc olunmayıb') }}).</div>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2 mb-6 text-sm">
        <span class="text-muted">{{ __('Sürətli keçid:') }}</span>
        @foreach(['Bugün' => $today, 'Dünən' => $today->subDay(), '1 həftə əvvəl' => $today->subWeek(), '1 ay əvvəl' => $today->subMonth()] as $label => $d)
            <a href="{{ route('currency.index', ['date' => $d->format('Y-m-d'), 'code' => $code]) }}"
               @class(['btn btn-sm', 'btn-secondary' => ! $d->isSameDay($date), 'bg-brand-soft text-brand-ink' => $d->isSameDay($date)])>{{ __($label) }}</a>
        @endforeach
    </div>

    @if($error)
        <div class="card border-saffron/40 bg-saffron-soft/60 p-4 mb-6 flex gap-3 text-sm" role="alert">
            <x-icon name="alert" class="size-5 text-saffron shrink-0"/>
            <div>{{ $error }} <span class="text-muted">{{ __('Mərkəzi Bank bu tarix üçün siyahı dərc etməyibsə və ya sayt əlçatan deyilsə, başqa tarix seçin.') }}</span></div>
        </div>
    @endif

    <div class="grid xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] gap-6 items-start">
        {{-- Chart --}}
        <section class="card p-5 lg:p-6 xl:sticky xl:top-24 order-last xl:order-first">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <form method="GET" action="{{ route('currency.index') }}" class="flex items-center gap-2">
                        <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}"><input type="hidden" name="days" value="{{ $days }}">
                        <select name="code" class="input !h-8 !w-auto !py-0 font-semibold" x-data @change="$el.form.submit()" aria-label="{{ __('Valyuta') }}">
                            @foreach(array_unique(array_merge(array_keys($list), [$code])) as $c)<option value="{{ $c }}" @selected($c === $code)>{{ $c }}</option>@endforeach
                        </select>
                        <h2 class="text-base font-semibold">/ AZN</h2>
                    </form>
                    <p class="text-xs text-muted">{{ __('1 vahid üçün manat · saxlanılan tarixçə') }}</p>
                </div>
                <div class="flex gap-1 p-1 rounded-lg bg-surface-2 border border-line">
                    @foreach([30 => __('30 gün'), 90 => __('90 gün'), 365 => __('1 il')] as $d => $l)
                        <a href="{{ route('currency.index', ['date' => $date->format('Y-m-d'), 'code' => $code, 'days' => $d]) }}"
                           @class(['px-2.5 h-7 grid place-items-center rounded-md text-xs font-medium', 'bg-surface shadow-sm text-ink' => $days === $d, 'text-muted hover:text-ink' => $days !== $d])>{{ $l }}</a>
                    @endforeach
                </div>
            </div>
            @if(count($history) > 1)
                @php
                    $chart = [
                        'type' => 'area', 'height' => 320, 'rate' => true, 'colors' => ['#0f9d8a'],
                        'series' => [['name' => $code, 'data' => array_values($history)]],
                        'categories' => array_map(fn ($d) => \Carbon\Carbon::parse($d)->format('d.m'), array_keys($history)),
                        'yaxis' => ['labels' => ['style' => ['fontSize' => '12px']], 'decimalsInFloat' => 4, 'tickAmount' => 5],
                        'xaxis' => ['tickAmount' => 8, 'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true]],
                    ];
                @endphp
                <div class="mt-4 -mx-2 h-[320px]" x-chart="{{ json_encode($chart) }}"><div class="skeleton h-full mx-2"></div></div>
                @php $vals = array_values($history); @endphp
                <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Minimum') }}</dt><dd class="font-mono font-semibold">{{ rate_fmt(min($vals)) }}</dd></div>
                    <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Orta') }}</dt><dd class="font-mono font-semibold">{{ rate_fmt(round(array_sum($vals) / count($vals), 4)) }}</dd></div>
                    <div class="rounded-lg bg-surface-2 py-2.5"><dt class="text-[11px] text-muted uppercase tracking-wider">{{ __('Maksimum') }}</dt><dd class="font-mono font-semibold">{{ rate_fmt(max($vals)) }}</dd></div>
                </dl>

                {{-- every bulletin of the period, newest first, with the change from the one before --}}
                @php
                    $dates = array_keys($history);
                    $daily = [];
                    foreach ($dates as $i => $d) {
                        $prevRate = $i ? $history[$dates[$i - 1]] : null;
                        $daily[] = ['date' => $d, 'rate' => $history[$d], 'diff' => $prevRate !== null ? $history[$d] - $prevRate : null,
                            'pct' => $prevRate ? ($history[$d] - $prevRate) / $prevRate * 100 : null];
                    }
                    $daily = array_reverse($daily);
                    $first = $vals[0];
                    $periodPct = $first ? (end($vals) - $first) / $first * 100 : 0;
                @endphp
                <div class="mt-5 flex items-baseline justify-between gap-3">
                    <h3 class="text-sm font-semibold">{{ __(':code — gündəlik məzənnələr', ['code' => $code]) }} <span class="font-mono font-normal text-muted">{{ count($daily) }}</span></h3>
                    <span class="text-xs text-muted">{{ __('Dövr üzrə') }}:
                        <span class="font-mono {{ $periodPct > 0.005 ? 'text-success' : ($periodPct < -0.005 ? 'text-danger' : 'text-faint') }}">{{ $periodPct > 0.005 ? '▲ +' : ($periodPct < -0.005 ? '▼ ' : '') }}{{ num($periodPct) }}%</span></span>
                </div>
                <div class="mt-2 rounded-lg border border-line overflow-y-auto max-h-[480px]">
                    <table class="table-g text-sm">
                        <thead class="sticky top-0 z-10"><tr>
                            <th>{{ __('Tarix') }}</th><th class="!text-right">{{ __('1 vahid') }}</th><th class="!text-right">{{ __('Dəyişmə') }}</th><th class="!text-right">%</th>
                        </tr></thead>
                        <tbody>
                        @foreach($daily as $row)
                            @php $up = ($row['diff'] ?? 0) > 0.000005; $down = ($row['diff'] ?? 0) < -0.000005; @endphp
                            <tr @class(['bg-brand-soft/40' => $row['date'] === $date->format('Y-m-d')])>
                                <td class="font-mono text-xs">{{ \Carbon\Carbon::parse($row['date'])->format('d.m.Y') }} <span class="text-faint font-sans">{{ \Carbon\Carbon::parse($row['date'])->locale(app()->getLocale())->isoFormat('dd') }}</span></td>
                                <td class="num font-medium text-ink">{{ rate_fmt($row['rate']) }}</td>
                                <td @class(['num', 'text-success' => $up, 'text-danger' => $down, 'text-faint' => ! $up && ! $down])>{{ $row['diff'] === null ? '—' : ($up ? '+' : ($down ? '−' : '')).rate_fmt(abs($row['diff'])) }}</td>
                                <td @class(['num text-xs', 'text-success' => $up, 'text-danger' => $down, 'text-faint' => ! $up && ! $down])>{{ $row['pct'] === null ? '' : ($up ? '▲ +' : ($down ? '▼ ' : '')).num($row['pct']).'%' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-empty icon="chart" :title="__('Tarixçə hələ toplanmayıb')" :text="__('Sistem hər gün məzənnələri avtomatik yükləyir. Qrafik bir neçə gündən sonra dolacaq.')"/>
            @endif
        </section>

        {{-- Table --}}
        <section class="card overflow-hidden">
            <table class="table-g table-stack">
                <thead>
                <tr>
                    <th>{{ __('Kod') }}</th>
                    <th>{{ __('Valyuta') }}</th>
                    <th class="!text-right">{{ __('Nominal') }}</th>
                    <th class="!text-right">{{ __('Məzənnə') }}</th>
                    <th class="!text-right">{{ __('1 vahid') }}</th>
                    <th class="!text-right">{{ __('Dəyişmə') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($list as $c => $r)
                    @php
                        $prev = $previous[$c]['rate'] ?? null;
                        $chg = $prev ? ($r['rate'] - $prev) / $prev * 100 : null;
                    @endphp
                    <tr @class(['bg-brand-soft/40' => $c === $code])>
                        <td data-label="{{ __('Kod') }}"><a href="{{ route('currency.index', ['date' => $date->format('Y-m-d'), 'code' => $c, 'days' => $days]) }}" class="font-semibold text-ink hover:text-brand-ink">{{ $c }}</a></td>
                        <td data-label="{{ __('Valyuta') }}" class="max-w-[220px] truncate">{{ $r['name'] }}</td>
                        <td data-label="Nominal" class="num">{{ num($r['nominal'], 0) }}</td>
                        <td data-label="{{ __('Məzənnə') }}" class="num">{{ rate_fmt($r['value']) }}</td>
                        <td data-label="1 vahid" class="num text-ink font-medium">{{ rate_fmt($r['rate']) }}</td>
                        <td data-label="{{ __('Dəyişmə') }}" class="num">
                            @if($chg === null)
                                <span class="text-faint">—</span>
                            @elseif(abs($chg) < 0.005)
                                <span class="text-faint">0,00%</span>
                            @else
                                <span class="{{ $chg > 0 ? 'text-success' : 'text-danger' }}">{{ $chg > 0 ? '▲ +' : '▼ ' }}{{ num($chg) }}%</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty icon="coins" :title="__('Məzənnə siyahısı yoxdur')"/></td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t border-line text-xs text-muted flex flex-wrap gap-x-4 gap-y-1">
                <span>{{ __('Mənbə:') }} <a href="{{ $sourceUrl }}" target="_blank" rel="noopener" class="text-brand-ink hover:underline">{{ __('cbar.az') }}</a></span>
                <span>{{ __('RUB, JPY və s. 100 vahid üçün dərc olunur; «1 vahid» sütunu hesablamalarda istifadə olunan dəyərdir.') }}</span>
            </div>
        </section>
    </div>
</x-layouts.app>
