<x-layouts.app :title="__('Təsisçi hesabatı')" wide>
    @php
        $d = $data;
        $loc = app()->getLocale();
        $period = azdate($d['from']).' — '.azdate($d['to']);
        $pct = fn ($now, $before) => $before == 0 ? null : round(($now - $before) / abs($before) * 100, 1);
        $dProfit = $pct($d['profit'], $d['previous']['profit']);
        $dRevenue = $pct($d['revenue'], $d['previous']['revenue']);
        $dExpenses = $pct($d['expenses'], $d['previous']['expenses']);
        $margin = $d['margin'];
        $avg = $d['tradeCount'] ? $d['profit'] / max(1, $d['tradeCount']) : null;
        $project = $projectId ? $projects->firstWhere('id', $projectId) : null;
        $deal = $dealId ? $dealList->firstWhere('id', $dealId) : null;
        $scope = $deal ? 'Trade '.$deal->code : ($project ? $project->name : __('Bütün şirkət'));

        $today = today();
        $q = fn ($a, $b) => ['from' => $a->toDateString(), 'to' => $b->toDateString()];
        $quick = [
            __('Bu ay') => $q($today->copy()->startOfMonth(), $today),
            __('Keçən ay') => $q($today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()),
            __('Bu rüb') => $q($today->copy()->firstOfQuarter(), $today),
            __('Bu il') => $q($today->copy()->startOfYear(), $today),
            __('Keçən il') => $q($today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()),
            __('Son 12 ay') => $q($today->copy()->subMonthsNoOverflow(11)->startOfMonth(), $today),
        ];
        $keep = array_filter(['project_id' => $projectId, 'deal_id' => $dealId]);

        // the charts
        $labels = array_map(fn ($m) => \Carbon\Carbon::createFromFormat('!Y-m', $m)->locale($loc)->isoFormat('MMM YY'), array_keys($d['months']));
        $trend = [
            'type' => 'bar', 'height' => 290, 'money' => true, 'colors' => ['#0f9d8a', '#f0a35a'],
            'series' => [
                ['name' => __('Gəlir'), 'data' => array_values(array_column($d['months'], 'revenue'))],
                ['name' => __('Xərclər'), 'data' => array_values(array_column($d['months'], 'expenses'))],
            ],
            'categories' => $labels, 'columnWidth' => '62%',
        ];
        // the profit on a scale of its own — next to the turnover it would be a flat line
        $profitChart = [
            'type' => 'area', 'height' => 290, 'money' => true, 'colors' => ['#0f9d8a'],
            'series' => [['name' => __('Xalis mənfəət'), 'data' => array_values(array_column($d['months'], 'profit'))]],
            'categories' => $labels, 'legend' => false,
        ];
        $partLabels = ['purchase' => __('Alış (satıcı)'), 'logistics' => __('Logistika'), 'fees' => __('Bank komissiyaları'), 'fx' => __('Bank kurs fərqləri'), 'overhead' => __('Şirkətin cari xərcləri')];
        $partColors = ['purchase' => '#1e3a8a', 'logistics' => '#f0a35a', 'fees' => '#8b5cf6', 'fx' => '#e5484d', 'overhead' => '#0ea5e9'];
        $parts = array_filter($d['parts'], fn ($v) => abs($v) >= 0.005);
        $donut = ['type' => 'donut', 'height' => 260, 'money' => true, 'totalLabel' => __('Xərclər'),
            'series' => array_values($parts), 'labels' => array_values(array_intersect_key($partLabels, $parts)), 'colors' => array_values(array_intersect_key($partColors, $parts)),
            'legend' => false];
        $maxTrade = max(1, ...array_map(fn ($t) => abs($t['profit']), $d['trades'] ?: [['profit' => 1]]));
        $maxOver = max(1, ...array_values($d['overheads'] ?: [1]));
        // short on a phone (8,59 mln ₼), whole on a wider screen
        $short = function (float $v): string {
            $a = abs($v);
            $s = $v < 0 ? '−' : '';

            return match (true) {
                $a >= 1e6 => $s.number_format($a / 1e6, 2, ',', ' ').' '.__('mln').' ₼',
                $a >= 1e4 => $s.number_format($a / 1e3, 1, ',', ' ').' '.__('min').' ₼',
                default => money($v),
            };
        };
        $amount = fn (float $v) => '<span class="sm:hidden">'.e($short($v)).'</span><span class="hidden sm:inline">'.e(money($v)).'</span>';
    @endphp

    <x-page-header :title="__('Təsisçi hesabatı')" :icon="$icon" :subtitle="$scope.' · '.$period"/>

    <nav class="flex gap-2 overflow-x-auto pb-1 mb-5 -mx-1 px-1 no-scrollbar" aria-label="{{ __('Hesabatlar') }}">
        @foreach($all as $key => [$navLabel, $ico])
            <a href="{{ route('analytics.show', $key) }}" @class(['badge !h-9 !px-3.5 !text-[13px] gap-1.5 shrink-0', 'badge-teal' => $key === $report, 'badge-slate hover:!text-ink' => $key !== $report]) @if($key === $report) aria-current="page" @endif>
                <x-icon :name="$ico" class="size-4"/> {{ __($navLabel) }}
            </a>
        @endforeach
    </nav>

    {{-- the filter: one tap on a phone, open on a wide screen --}}
    <form method="GET" action="{{ route('analytics.show', 'founders') }}" class="card mb-5"
          x-data="{ open: false, project: @js((string) ($projectId ?? '')), deal: @js((string) ($dealId ?? '')),
                    deals: @js($dealList->map(fn ($x) => ['id' => $x->id, 'p' => $x->project_id, 'label' => $x->code.' · '.($x->supplier?->name ?? '—').' → '.($x->counterparty?->name ?? '—')])) }">
        <button type="button" class="lg:hidden w-full flex items-center justify-between gap-3 px-4 py-3.5 text-left" @click="open = !open" :aria-expanded="open">
            <span class="min-w-0">
                <span class="block text-[11px] uppercase tracking-wider text-muted">{{ __('Filtr') }}</span>
                <span class="block text-sm font-medium truncate">{{ $scope }} · {{ $period }}</span>
            </span>
            <x-icon name="filter" class="size-5 text-brand shrink-0"/>
        </button>
        <div class="px-4 pb-4 lg:pt-4 space-y-3" :class="open ? 'block' : 'hidden lg:block'">
            <div class="grid grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto] gap-3 items-end">
                <label><span class="field-label">{{ __('Tarixdən') }}</span><input type="date" name="from" value="{{ $d['from'] }}" class="input"></label>
                <label><span class="field-label">{{ __('Tarixədək') }}</span><input type="date" name="to" value="{{ $d['to'] }}" class="input"></label>
                <label class="col-span-2 lg:col-span-1"><span class="field-label">{{ __('Layihə') }}</span>
                    <select name="project_id" class="input" x-model="project" @change="deal = ''">
                        <option value="">{{ __('Bütün layihələr') }}</option>
                        @foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select></label>
                <label class="col-span-2 lg:col-span-1"><span class="field-label">Trade</span>
                    <select name="deal_id" class="input" x-model="deal">
                        <option value="">{{ __('Bütün Trade-lər') }}</option>
                        <template x-for="x in deals.filter(x => !project || String(x.p) === project)" :key="x.id"><option :value="String(x.id)" x-text="x.label" :selected="String(x.id) === deal"></option></template>
                    </select></label>
                <button class="btn btn-primary col-span-2 lg:col-span-1 justify-center"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
            </div>
            <div class="flex gap-1.5 overflow-x-auto no-scrollbar -mx-1 px-1">
                @foreach($quick as $ql => $range)
                    <a href="{{ route('analytics.show', ['founders'] + $range + $keep) }}" @class(['btn btn-sm shrink-0 rounded-full', 'bg-brand-soft text-brand-ink' => $range['from'] === $d['from'] && $range['to'] === $d['to'], 'btn-ghost border border-line' => ! ($range['from'] === $d['from'] && $range['to'] === $d['to'])])>{{ $ql }}</a>
                @endforeach
                @if($projectId || $dealId)<a href="{{ route('analytics.show', ['founders', 'from' => $d['from'], 'to' => $d['to']]) }}" class="btn btn-sm btn-ghost shrink-0 text-danger">{{ __('Filtri təmizlə') }}</a>@endif
            </div>
        </div>
    </form>

    {{-- the result, first --}}
    <section class="relative overflow-hidden rounded-3xl text-white p-6 sm:p-8 mb-5 shadow-lg" style="background: radial-gradient(120% 140% at 100% 0%, #14b8a6 0%, #0f766e 38%, #0b3b3a 100%)">
        <div class="absolute -right-16 -bottom-20 size-64 rounded-full bg-white/5" aria-hidden="true"></div>
        <div class="absolute right-10 -top-10 size-40 rounded-full bg-white/5" aria-hidden="true"></div>
        <div class="relative flex flex-col sm:flex-row sm:items-center gap-6">
            <div class="flex-1 min-w-0">
                <div class="text-[12px] uppercase tracking-[.18em] text-white/70">{{ __('Xalis mənfəət') }}</div>
                <div class="mt-2 font-mono font-semibold tracking-tight tabular-nums text-[2.15rem] leading-none sm:text-5xl">
                    <span x-data x-countup="{{ $d['profit'] }}" data-decimals="2" data-suffix=" ₼">{{ money($d['profit']) }}</span>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-[13px]">
                    @if($dProfit !== null)
                        <span @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium', 'bg-emerald-400/20 text-emerald-100' => $dProfit >= 0, 'bg-rose-400/25 text-rose-100' => $dProfit < 0])>
                            <x-icon :name="$dProfit >= 0 ? 'trending-up' : 'trending-down'" class="size-4"/> {{ $dProfit >= 0 ? '+' : '' }}{{ number_format($dProfit, 1, ',', ' ') }}%
                        </span>
                        <span class="text-white/70">{{ __('əvvəlki dövrə görə') }} ({{ money($d['previous']['profit']) }})</span>
                    @else
                        <span class="text-white/70">{{ __('əvvəlki dövrdə müqayisə üçün məlumat yoxdur') }}</span>
                    @endif
                </div>
            </div>
            {{-- the margin as a ring --}}
            @php $ring = max(0, min(100, (float) ($margin ?? 0))); @endphp
            <div class="flex sm:flex-col items-center gap-4 sm:gap-2">
                <div class="relative size-24 sm:size-28 rounded-full grid place-items-center" style="background: conic-gradient(#fde68a {{ $ring * 3.6 }}deg, rgba(255,255,255,.14) 0)">
                    <div class="absolute inset-[9px] rounded-full" style="background: #0d4f4c"></div>
                    <div class="relative text-center leading-tight"><div class="font-mono text-xl font-semibold">{{ $margin === null ? '—' : number_format($margin, 1, ',', ' ').'%' }}</div><div class="text-[10px] uppercase tracking-wider text-white/60">{{ __('marja') }}</div></div>
                </div>
                <div class="sm:hidden text-[13px] text-white/80 leading-relaxed">{{ __('Hər 100 ₼ gəlirdən xalis qalan') }}: <span class="font-semibold text-white">{{ $margin === null ? '—' : number_format($margin, 1, ',', ' ').' ₼' }}</span></div>
            </div>
        </div>
        <div class="relative mt-6 grid grid-cols-3 gap-2 sm:gap-4 border-t border-white/15 pt-4">
            <div class="min-w-0"><div class="text-[11px] text-white/60">{{ __('Gəlir') }}</div><div class="font-mono text-sm sm:text-lg font-semibold tabular-nums truncate">{!! $amount($d['revenue']) !!}</div></div>
            <div class="min-w-0"><div class="text-[11px] text-white/60">{{ __('Xərclər') }}</div><div class="font-mono text-sm sm:text-lg font-semibold tabular-nums truncate">{!! $amount($d['expenses']) !!}</div></div>
            <div><div class="text-[11px] text-white/60">{{ __('Bağlanmış Trade') }}</div><div class="font-mono text-sm sm:text-lg font-semibold tabular-nums">{{ $d['tradeCount'] }}</div></div>
        </div>
    </section>

    {{-- the four numbers --}}
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4 mb-5 stagger [&>*]:min-w-0">
        @foreach([
            [__('Gəlir'), $d['revenue'], $dRevenue, false, 'arrow-down-left', 'text-success', $d['otherIncome'] > 0 ? __('o cümlədən faiz gəlirləri').': '.money($d['otherIncome']) : __('Trade-lərin satışı')],
            [__('Xərclər'), $d['expenses'], $dExpenses, true, 'arrow-up-right', 'text-danger', __('alış, logistika, komissiya, kurs fərqi').($d['company'] ? ', '.__('cari xərclər') : '')],
        ] as $i => [$label, $value, $delta, $inverse, $ico, $tone, $hint])
            <div class="card p-4 sm:p-5" style="--i:{{ $i }}">
                <div class="flex items-center justify-between gap-2"><span class="text-xs text-muted">{{ $label }}</span><span class="grid place-items-center size-8 rounded-xl bg-surface-2 {{ $tone }}"><x-icon :name="$ico" class="size-4"/></span></div>
                <div class="mt-2 font-mono text-lg sm:text-2xl font-semibold tabular-nums truncate" title="{{ money($value) }}">{!! $amount($value) !!}</div>
                @if($delta !== null)
                    @php $good = $inverse ? $delta <= 0 : $delta >= 0; @endphp
                    <div @class(['mt-1 text-[12px] font-medium', 'text-success' => $good, 'text-danger' => ! $good])>{{ $delta >= 0 ? '↑' : '↓' }} {{ number_format(abs($delta), 1, ',', ' ') }}% <span class="text-muted font-normal">{{ __('əvvəlki dövrə görə') }}</span></div>
                @endif
                <div class="mt-1 text-[11px] text-faint leading-snug line-clamp-2">{{ $hint }}</div>
            </div>
        @endforeach
        <div class="card p-4 sm:p-5" style="--i:2">
            <div class="flex items-center justify-between gap-2"><span class="text-xs text-muted">{{ __('Trade başına mənfəət') }}</span><span class="grid place-items-center size-8 rounded-xl bg-surface-2 text-brand"><x-icon name="target" class="size-4"/></span></div>
            <div class="mt-2 font-mono text-lg sm:text-2xl font-semibold tabular-nums truncate">{!! $avg === null ? '—' : $amount($avg) !!}</div>
            <div class="mt-1 text-[11px] text-faint">{{ $d['tradeCount'] }} {{ __('bağlanmış Trade') }}</div>
        </div>
        <div class="card p-4 sm:p-5 bg-saffron-soft/40 border-saffron/30" style="--i:3">
            <div class="flex items-center justify-between gap-2"><span class="text-xs text-muted">{{ __('Yolda olan Trade-lər') }}</span><span class="grid place-items-center size-8 rounded-xl bg-saffron-soft text-saffron"><x-icon name="truck" class="size-4"/></span></div>
            <div class="mt-2 font-mono text-lg sm:text-2xl font-semibold tabular-nums truncate">{!! $amount($d['open']['forecast']) !!}</div>
            <div class="mt-1 text-[11px] text-faint leading-snug">{{ $d['open']['count'] }} {{ __('Trade aktı gözləyir — proqnoz mənfəət') }}</div>
        </div>
    </div>

    {{-- by month: the turnover, and the profit on its own scale --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 mb-5">
        <section class="card p-4 sm:p-5 lg:col-span-3 min-w-0">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">{{ __('Gəlir və xərclər aylar üzrə') }}</h2>
                <span class="text-xs text-muted">AZN</span>
            </div>
            <div class="mt-2 -mx-2 h-[290px]" x-chart="{{ json_encode($trend) }}"><div class="skeleton h-full mx-2"></div></div>
        </section>
        <section class="card p-4 sm:p-5 lg:col-span-2 min-w-0">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">{{ __('Xalis mənfəət aylar üzrə') }}</h2>
                @php $best = collect($d['months'])->sortByDesc('profit')->keys()->first(); @endphp
                @if($best && $d['months'][$best]['profit'] > 0)<span class="text-xs text-muted">{{ __('ən yaxşı ay') }}: <span class="font-medium text-ink">{{ \Carbon\Carbon::createFromFormat('!Y-m', $best)->locale($loc)->isoFormat('MMMM') }}</span></span>@endif
            </div>
            <div class="mt-2 -mx-2 h-[290px]" x-chart="{{ json_encode($profitChart) }}"><div class="skeleton h-full mx-2"></div></div>
        </section>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 mb-5">
        {{-- where the money goes --}}
        <section class="card p-4 sm:p-5 lg:col-span-2 min-w-0">
            <h2 class="text-base font-semibold">{{ __('Xərclərin strukturu') }}</h2>
            @if($parts)
                <div class="mt-1 -mx-2 h-[260px]" x-chart="{{ json_encode($donut) }}"><div class="skeleton h-full mx-2"></div></div>
                <ul class="mt-2 space-y-2.5">
                    @foreach($parts as $k => $v)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="size-2.5 rounded-full shrink-0" style="background: {{ $partColors[$k] }}"></span>
                            <span class="flex-1 min-w-0 truncate">{{ $partLabels[$k] }}</span>
                            <span class="font-mono tabular-nums">{{ money($v) }}</span>
                            <span class="w-12 text-right text-[12px] text-muted font-mono">{{ $d['expenses'] > 0 ? number_format($v / $d['expenses'] * 100, 1, ',', ' ') : 0 }}%</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <x-empty icon="pie" :title="__('Bu dövrdə xərc yoxdur')" :text="__('Dövrü və ya filtri dəyişin.')"/>
            @endif
        </section>

        {{-- the best Trades --}}
        <section class="card p-4 sm:p-5 lg:col-span-3 min-w-0" x-data="{ all: false }">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">{{ __('Ən gəlirli Trade-lər') }}</h2>
                <span class="text-xs text-muted">{{ $d['tradeCount'] }} {{ __('Trade') }}</span>
            </div>
            @if($d['trades'])
                <ol class="mt-3 space-y-1">
                    @foreach($d['trades'] as $i => $t)
                        @php $m = $t['revenue'] > 0 ? $t['profit'] / $t['revenue'] * 100 : 0; @endphp
                        <li @if($i >= 6) x-show="all" x-cloak @endif>
                            <a href="{{ $t['url'] }}" class="block rounded-xl px-2 py-2.5 hover:bg-surface-2 transition">
                                <div class="flex items-center gap-3">
                                    <span @class(['grid place-items-center size-8 rounded-full text-[12px] font-semibold shrink-0', 'bg-brand text-white' => $i < 3, 'bg-surface-2 text-muted' => $i >= 3])>{{ $i + 1 }}</span>
                                    <span class="flex-1 min-w-0">
                                        <span class="block text-sm font-medium truncate">{{ $t['code'] }} <span class="text-muted font-normal">· {{ $t['title'] }}</span></span>
                                        <span class="block text-[11px] text-faint truncate">{{ azdate($t['day']) }} · {{ implode(', ', $t['invoices']) }}</span>
                                    </span>
                                    <span class="text-right shrink-0">
                                        <span @class(['block font-mono text-sm font-semibold tabular-nums', 'text-success' => $t['profit'] > 0, 'text-danger' => $t['profit'] < 0])>{{ money($t['profit']) }}</span>
                                        <span class="block text-[11px] text-muted font-mono">{{ number_format($m, 1, ',', ' ') }}%</span>
                                    </span>
                                </div>
                                <div class="mt-2 ml-11 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div @class(['h-full rounded-full', 'bg-brand' => $t['profit'] >= 0, 'bg-danger' => $t['profit'] < 0]) style="width: {{ round(abs($t['profit']) / $maxTrade * 100, 1) }}%"></div></div>
                            </a>
                        </li>
                    @endforeach
                </ol>
                @if(count($d['trades']) > 6)
                    <button type="button" class="btn btn-ghost btn-sm w-full mt-2" @click="all = !all" x-text="all ? @js(__('Daha az göstər')) : @js(__('Hamısını göstər').' ('.count($d['trades']).')')"></button>
                @endif
            @else
                <x-empty icon="layers" :title="__('Bu dövrdə bağlanmış Trade yoxdur')" :text="__('Trade aktı olanda (mal təhvil veriləndə) burada görünür.')"/>
            @endif
        </section>
    </div>

    {{-- by project, swipe on a phone --}}
    @if(count($d['projects']) > 1)
        <section class="mb-5">
            <h2 class="text-base font-semibold mb-3">{{ __('Layihələr üzrə') }}</h2>
            <div class="flex lg:grid lg:grid-cols-3 gap-3 overflow-x-auto snap-x snap-mandatory no-scrollbar -mx-1 px-1 pb-1">
                @foreach($d['projects'] as $p)
                    @php $pm = $p['revenue'] > 0 ? $p['profit'] / $p['revenue'] * 100 : 0; @endphp
                    <div class="card p-4 min-w-[78%] sm:min-w-[48%] lg:min-w-0 snap-start">
                        <div class="text-[11px] font-mono text-faint">{{ $p['code'] }}</div>
                        <div class="font-medium truncate">{{ $p['name'] }}</div>
                        <div class="mt-3 font-mono text-xl font-semibold tabular-nums">{{ money($p['profit']) }}</div>
                        <div class="text-[12px] text-muted">{{ __('gəlir') }} {{ money($p['revenue']) }} · {{ $p['count'] }} Trade · {{ number_format($pm, 1, ',', ' ') }}%</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- the company's running costs --}}
    @if($d['company'] && $d['overheads'])
        <section class="card p-4 sm:p-5 mb-5">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">{{ __('Şirkətin cari xərcləri') }}</h2>
                <span class="font-mono text-sm text-muted">{{ money($d['parts']['overhead']) }}</span>
            </div>
            <ul class="mt-3 space-y-3">
                @foreach($d['overheads'] as $name => $v)
                    <li>
                        <div class="flex items-center justify-between gap-3 text-sm"><span class="truncate">{{ $name }}</span><span class="font-mono tabular-nums">{{ money($v) }}</span></div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full rounded-full bg-sky-500" style="width: {{ round($v / $maxOver * 100, 1) }}%"></div></div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="text-[11px] text-muted leading-relaxed px-1">
        {{ __('Trade aktının (malın təhvil verildiyi) tarixinə görə hesablanır. Gəlir — satış ödəniş gününün CBAR kursu ilə; xərclər — satıcıya alış, logistika, bank komissiyaları və bankın kursu ilə CBAR arasındakı fərq; Trade-in mənfəəti Yekun hesabatdakı «Gəlir — kurs fərqləri ilə»dir.') }}
        @if($d['company']) {{ __('Bütün şirkət üzrə baxanda şirkətin cari xərcləri (əmək haqqı, DSMF, xidmətlər, ölkədaxili bank komissiyası) çıxılır və faiz gəlirləri əlavə olunur; mənfəət vergisi və əsas vəsait alışı xərc sayılmır.') }}@else {{ __('Layihə və ya Trade seçiləndə yalnız Trade-lərin nəticəsi göstərilir — şirkətin cari xərcləri daxil deyil.') }}@endif
        @if($d['incomplete']) <span class="text-saffron">{{ __(':v1 Trade-in məlumatı natamamdır (ödəniş və ya kurs yoxdur) və hesaba daxil edilmədi.', ['v1' => $d['incomplete']]) }}</span>@endif
    </p>
</x-layouts.app>
