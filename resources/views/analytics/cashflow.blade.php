<x-layouts.app :title="__('Cash flow')" wide>
    <x-page-header title="Cash flow" :icon="$icon" :subtitle="__('Aylar üzrə pul axını, AZN — daxilolmalar və ödənişlər kontragent üzrə, CBAR kursu ilə; komissiyalar və kurs fərqi ayrıca')">
        <x-slot:actions>
            <a href="{{ route('analytics.show', array_filter(['report' => 'cashflow', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'format' => 'xlsx'])) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
        </x-slot:actions>
    </x-page-header>

    <nav class="flex gap-2 overflow-x-auto pb-1 mb-6" aria-label="{{ __('Hesabatlar') }}">
        @foreach($all as $key => [$navLabel, $ico])
            <a href="{{ route('analytics.show', $key) }}" @class(['badge !h-9 !px-3.5 !text-[13px] gap-1.5 shrink-0', 'badge-teal' => $key === $report, 'badge-slate hover:!text-ink' => $key !== $report]) @if($key === $report) aria-current="page" @endif>
                <x-icon :name="$ico" class="size-4"/> {{ __($navLabel) }}
            </a>
        @endforeach
    </nav>

    @php
        $months = $data['months'];
        $sumIn = array_sum($data['in']);
        $sumOut = array_sum($data['out']);
        $openFirst = reset($data['opening']) ?: 0;
        $closeLast = end($data['closing']) ?: 0;
        $n = fn ($v) => abs($v) < 0.005 ? '—' : ($v < 0 ? '−' : '').num(abs($v));
        $base = array_filter(['report' => 'cashflow', 'project_id' => $projectId]);
        $now = today();
        $quick = [
            __('Son 6 ay') => [$now->copy()->subMonthsNoOverflow(5)->format('Y-m'), $now->format('Y-m')],
            __('Son 12 ay') => [$now->copy()->subMonthsNoOverflow(11)->format('Y-m'), $now->format('Y-m')],
            __('Bu il') => [$now->format('Y').'-01', $now->format('Y-m')],
            __('Keçən il') => [($now->year - 1).'-01', ($now->year - 1).'-12'],
        ];
        if ($data['first']) {
            $quick[__('Hamısı')] = [$data['first'], max($data['last'], $now->format('Y-m'))];
        }
        $chart = [
            'type' => 'line', 'height' => 300, 'money' => true, 'colors' => ['#16a34a', '#e11d48', '#0f9d8a'],
            'series' => [
                ['name' => __('Gəlirlər'), 'type' => 'column', 'data' => array_values(array_map(fn ($v) => round($v, 2), $data['in']))],
                ['name' => __('Ödənişlər'), 'type' => 'column', 'data' => array_values(array_map(fn ($v) => round($v, 2), $data['out']))],
                ['name' => __('Dövrün sonuna qalıq'), 'type' => 'line', 'data' => array_values(array_map(fn ($v) => round($v, 2), $data['closing']))],
            ],
            'categories' => array_map($label, $months), 'stroke' => ['width' => [0, 0, 3], 'curve' => 'smooth'], 'columnWidth' => '58%',
        ];
        $kindIcon = ['fees' => 'percent', 'fx' => 'transfer', 'category' => 'receipt'];

        // the explanation behind every figure, for the popup: the money movements of a line, or the lines of a total
        $period = $label($from).' — '.$label($to);
        $why = [];
        $sorted = fn (array $rows) => collect($rows)->sortBy('s')->values()->all();
        foreach (['i' => $data['income'], 'o' => $data['payments']] as $side => $groups) {
            foreach ($groups as $gi => $g) {
                foreach ($months as $m) {
                    $why["{$side}{$gi}|{$m}"] = ['h' => $g['label'], 's' => $label($m), 'rows' => $sorted($g['details'][$m] ?? []), 'v' => $g['values'][$m], 'fx' => $g['kind'] === 'fx'];
                }
                $why["{$side}{$gi}|all"] = ['h' => $g['label'], 's' => $period, 'rows' => $sorted(array_merge([], ...array_values($g['details']))), 'v' => $g['total'], 'fx' => $g['kind'] === 'fx'];
            }
        }
        $lines = fn (array $groups, ?string $m) => collect($groups)->map(fn ($g) => ['l' => $g['label'], 'v' => $m ? $g['values'][$m] : $g['total']])->filter(fn ($x) => abs($x['v']) >= 0.005)->values()->all();
        foreach (array_merge($months, [null]) as $i => $m) {
            $k = $m ?? 'all';
            $s = $m ? $label($m) : $period;
            $in = $m ? $data['in'][$m] : $sumIn;
            $out = $m ? $data['out'][$m] : $sumOut;
            $why["in|{$k}"] = ['h' => __('Cəmi gəlirlər'), 's' => $s, 'lines' => $lines($data['income'], $m), 'v' => $in];
            $why["out|{$k}"] = ['h' => __('Cəmi ödənişlər'), 's' => $s, 'lines' => $lines($data['payments'], $m), 'v' => $out];
            $why["net|{$k}"] = ['h' => __('Xalis pul axını'), 's' => $s, 'f' => __('Cəmi gəlirlər − Cəmi ödənişlər'), 'lines' => [['l' => __('Cəmi gəlirlər'), 'v' => $in], ['l' => __('Cəmi ödənişlər'), 'v' => -$out]], 'v' => $in - $out];
            $open = $m ? $data['opening'][$m] : $openFirst;
            $why["open|{$k}"] = ['h' => __('Dövrün əvvəlinə qalıq'), 's' => $s,
                'f' => $m && $m !== $from ? __('Əvvəlki ayın sonuna qalıq') : __(':v1 ayından əvvəlki bütün daxilolmalar − ödənişlər', ['v1' => $label($from)]),
                'lines' => $m && $m !== $from
                    ? [['l' => __('Dövrün sonuna qalıq').' · '.$label($months[$i - 1]), 'v' => $data['closing'][$months[$i - 1]]]]
                    : [['l' => __('Əvvəlki daxilolmalar'), 'v' => $data['before']['in']], ['l' => __('Əvvəlki ödənişlər'), 'v' => -$data['before']['out']]],
                'v' => $open];
            $why["close|{$k}"] = ['h' => __('Dövrün sonuna qalıq'), 's' => $s, 'f' => __('Dövrün əvvəlinə qalıq + Xalis pul axını'),
                'lines' => [['l' => __('Dövrün əvvəlinə qalıq'), 'v' => $open], ['l' => __('Xalis pul axını'), 'v' => $in - $out]], 'v' => $m ? $data['closing'][$m] : $closeLast];
        }
        // a clickable figure
        $tip = fn (string $key) => 'role="button" tabindex="0" @click="show(\''.$key.'\')" @keydown.enter="show(\''.$key.'\')"';
    @endphp

    <form method="GET" action="{{ route('analytics.show', 'cashflow') }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3">
        <label><span class="field-label">{{ __('Ayından') }}</span><input type="month" name="from" value="{{ $from }}" class="input font-mono"></label>
        <label><span class="field-label">{{ __('Ayınadək') }}</span><input type="month" name="to" value="{{ $to }}" class="input font-mono"></label>
        <label class="min-w-[240px] flex-1">
            <span class="field-label">{{ __('Layihə') }}</span>
            <select name="project_id" class="input">
                <option value="">{{ __('Bütün şirkət') }}</option>
                @foreach($projects as $p)<option value="{{ $p->id }}" @selected($projectId === $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach
            </select>
        </label>
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        <div class="flex flex-wrap gap-1 w-full lg:w-auto lg:ml-auto">
            @foreach($quick as $ql => [$qf, $qt])
                <a href="{{ route('analytics.show', $base + ['from' => $qf, 'to' => $qt]) }}" @class(['btn btn-sm', 'btn-ghost' => ! ($qf === $from && $qt === $to), 'bg-brand-soft text-brand-ink' => $qf === $from && $qt === $to])>{{ $ql }}</a>
            @endforeach
        </div>
    </form>

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
        <div class="card p-5" style="--i:0">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="wallet" class="size-4"/> {{ __('Dövrün əvvəlinə qalıq') }}</div>
            <div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($openFirst) }}</div>
            <div class="text-[11px] text-faint">{{ $label($from) }}</div>
        </div>
        <div class="card p-5" style="--i:1">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="arrow-down-left" class="size-4 text-success"/> {{ __('Gəlirlər') }}</div>
            <div class="mt-1 text-2xl font-semibold font-mono tabular-nums text-success">+{{ money($sumIn) }}</div>
            <div class="text-[11px] text-faint">{{ count($data['income']) }} {{ __('mənbə') }}</div>
        </div>
        <div class="card p-5" style="--i:2">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="arrow-up-right" class="size-4 text-danger"/> {{ __('Ödənişlər') }}</div>
            <div class="mt-1 text-2xl font-semibold font-mono tabular-nums text-danger">−{{ money($sumOut) }}</div>
            <div class="text-[11px] text-faint">{{ count($data['payments']) }} {{ __('maddə') }}</div>
        </div>
        <div class="card p-5" style="--i:3">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="layers" class="size-4"/> {{ __('Dövrün sonuna qalıq') }}</div>
            <div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-danger' => $closeLast < 0])>{{ money($closeLast) }}</div>
            <div @class(['text-[11px]', 'text-success' => $sumIn - $sumOut > 0, 'text-danger' => $sumIn - $sumOut < 0])>{{ __('xalis axın') }}: {{ ($sumIn - $sumOut >= 0 ? '+' : '−').money(abs($sumIn - $sumOut)) }}</div>
        </div>
    </div>

    @if(! $data['income'] && ! $data['payments'])
        <div class="card"><x-empty icon="trending-up" :title="__('Bu dövrdə pul hərəkəti yoxdur')" :text="__('Mədaxillər, satıcıya və logistikaya ödənişlər, xərclər və valyuta alış-satışı burada aylar üzrə toplanır.')"/></div>
    @else
        <section class="card p-5 mb-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">{{ __('Aylar üzrə') }}</h2>
                <span class="text-xs text-muted">{{ $label($from) }} — {{ $label($to) }} · AZN</span>
            </div>
            <div class="mt-3 -mx-2 h-[300px]" x-chart="{{ json_encode($chart) }}"><div class="skeleton h-full mx-2"></div></div>
        </section>

        <section class="card overflow-hidden" x-data="{ open: null, why: @js($why), show(k) { if (this.why[k]) this.open = k; } }" x-init="const c = new URLSearchParams(location.search).get('cell'); if (c) show(c)">
            <div class="overflow-auto max-h-[80vh]">
                <table class="text-[13px] border-separate border-spacing-0 min-w-full">
                    <thead class="sticky top-0 z-20">
                        <tr class="bg-[#9a4a0c] text-white">
                            <th class="sticky left-0 z-30 bg-[#9a4a0c] px-4 py-3 text-left text-sm font-semibold min-w-[260px]">CASH FLOW <span class="font-normal text-white/70 text-xs">· AZN</span></th>
                            @foreach($months as $m)<th class="px-4 py-3 text-right font-semibold whitespace-nowrap min-w-[120px] border-l border-white/15">{{ $label($m) }}</th>@endforeach
                            <th class="px-4 py-3 text-right font-semibold whitespace-nowrap min-w-[130px] border-l border-white/30 bg-[#7c3a08]">{{ __('Cəmi') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- opening --}}
                        <tr class="bg-saffron-soft font-semibold">
                            <td class="sticky left-0 z-10 bg-saffron-soft px-4 py-2.5">{{ __('Dövrün əvvəlinə qalıq') }}</td>
                            @foreach($months as $m)<td @class(['cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums', 'text-danger' => $data['opening'][$m] < 0]) {!! $tip("open|$m") !!}>{{ $n($data['opening'][$m]) }}</td>@endforeach
                            <td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums" {!! $tip('open|all') !!}>{{ $n($openFirst) }}</td>
                        </tr>

                        {{-- money in --}}
                        <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 pt-5 pb-2 text-xs font-bold uppercase tracking-wider text-success"><span class="inline-flex items-center gap-1.5"><x-icon name="arrow-down-left" class="size-4"/> {{ __('Gəlirlər') }}</span></td></tr>
                        @forelse($data['income'] as $gi => $g)
                            <tr class="group">
                                <td class="sticky left-0 z-10 bg-surface group-hover:bg-surface-2 px-4 py-2 border-b border-line">
                                    @if($g['url'])<a href="{{ $g['url'] }}" class="hover:text-brand-ink">{{ $g['label'] }}</a>@else{{ $g['label'] }}@endif
                                </td>
                                @foreach($months as $m)<td @class(['px-4 py-2 text-right font-mono tabular-nums border-b border-line group-hover:bg-surface-2', 'text-faint' => abs($g['values'][$m]) < 0.005, 'cursor-pointer hover:underline decoration-dotted underline-offset-4' => abs($g['values'][$m]) >= 0.005]) @if(abs($g['values'][$m]) >= 0.005) {!! $tip("i$gi|$m") !!} @endif>{{ $n($g['values'][$m]) }}</td>@endforeach
                                <td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2 text-right font-mono tabular-nums font-semibold border-b border-line bg-surface-2/60 group-hover:bg-surface-2" {!! $tip("i$gi|all") !!}>{{ $n($g['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 py-2 text-muted text-xs">{{ __('Daxilolma yoxdur') }}</td></tr>
                        @endforelse
                        <tr class="bg-success-soft font-semibold">
                            <td class="sticky left-0 z-10 bg-success-soft px-4 py-2.5">{{ __('Cəmi gəlirlər') }}</td>
                            @foreach($months as $m)<td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums text-success" {!! $tip("in|$m") !!}>{{ $n($data['in'][$m]) }}</td>@endforeach
                            <td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums text-success" {!! $tip('in|all') !!}>{{ $n($sumIn) }}</td>
                        </tr>

                        {{-- money out --}}
                        <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 pt-6 pb-2 text-xs font-bold uppercase tracking-wider text-danger"><span class="inline-flex items-center gap-1.5"><x-icon name="arrow-up-right" class="size-4"/> {{ __('Ödənişlər') }}</span></td></tr>
                        @forelse($data['payments'] as $gi => $g)
                            @php $special = $g['kind'] !== 'party'; @endphp
                            <tr class="group">
                                <td @class(['sticky left-0 z-10 bg-surface group-hover:bg-surface-2 px-4 py-2 border-b border-line', 'text-ink-2' => $special])>
                                    <span class="inline-flex items-center gap-1.5">
                                        @isset($kindIcon[$g['kind']])<x-icon :name="$kindIcon[$g['kind']]" class="size-3.5 text-faint"/>@endisset
                                        @if($g['url'])<a href="{{ $g['url'] }}" class="hover:text-brand-ink">{{ $g['label'] }}</a>@else{{ $g['label'] }}@endif
                                    </span>
                                </td>
                                @foreach($months as $m)
                                    @php $v = $g['values'][$m]; @endphp
                                    <td @class(['px-4 py-2 text-right font-mono tabular-nums border-b border-line group-hover:bg-surface-2', 'text-faint' => abs($v) < 0.005, 'text-success' => $v < -0.005, 'cursor-pointer hover:underline decoration-dotted underline-offset-4' => abs($v) >= 0.005])
                                        @if($g['kind'] === 'fx' && $v < -0.005) title="{{ __('qazanc') }}" @endif @if(abs($v) >= 0.005) {!! $tip("o$gi|$m") !!} @endif>{{ $n($v) }}</td>
                                @endforeach
                                <td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2 text-right font-mono tabular-nums font-semibold border-b border-line bg-surface-2/60 group-hover:bg-surface-2" {!! $tip("o$gi|all") !!}>{{ $n($g['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 py-2 text-muted text-xs">{{ __('Ödəniş yoxdur') }}</td></tr>
                        @endforelse
                        <tr class="bg-danger-soft font-semibold">
                            <td class="sticky left-0 z-10 bg-danger-soft px-4 py-2.5">{{ __('Cəmi ödənişlər') }}</td>
                            @foreach($months as $m)<td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums text-danger" {!! $tip("out|$m") !!}>{{ $n($data['out'][$m]) }}</td>@endforeach
                            <td class="cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums text-danger" {!! $tip('out|all') !!}>{{ $n($sumOut) }}</td>
                        </tr>

                        {{-- result --}}
                        <tr><td colspan="{{ count($months) + 2 }}" class="h-4"></td></tr>
                        <tr class="font-semibold">
                            <td class="sticky left-0 z-10 bg-surface px-4 py-2.5 border-y border-line">{{ __('Xalis pul axını') }}</td>
                            @foreach($months as $m)<td @class(['cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums border-y border-line', 'text-success' => $data['net'][$m] > 0.005, 'text-danger' => $data['net'][$m] < -0.005]) {!! $tip("net|$m") !!}>{{ $data['net'][$m] > 0.005 ? '+' : '' }}{{ $n($data['net'][$m]) }}</td>@endforeach
                            <td @class(['cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-2.5 text-right font-mono tabular-nums border-y border-line', 'text-success' => $sumIn - $sumOut > 0, 'text-danger' => $sumIn - $sumOut < 0]) {!! $tip('net|all') !!}>{{ $n($sumIn - $sumOut) }}</td>
                        </tr>
                        <tr class="bg-[#9a4a0c]/10 font-bold">
                            <td class="sticky left-0 z-10 bg-[#f6ebe2] dark:bg-[#3a2416] px-4 py-3">{{ __('Dövrün sonuna qalıq') }}</td>
                            @foreach($months as $m)<td @class(['cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-3 text-right font-mono tabular-nums', 'text-danger' => $data['closing'][$m] < 0]) {!! $tip("close|$m") !!}>{{ $n($data['closing'][$m]) }}</td>@endforeach
                            <td @class(['cursor-pointer hover:underline decoration-dotted underline-offset-4 px-4 py-3 text-right font-mono tabular-nums', 'text-danger' => $closeLast < 0]) {!! $tip('close|all') !!}>{{ $n($closeLast) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="px-5 py-3 border-t border-line text-[11px] text-muted leading-relaxed">
                <span class="font-medium text-ink-2">{{ __('Rəqəmə toxunun — necə yarandığı açılacaq.') }}</span>
                {{ __('Daxilolmalar və ödənişlər öz tarixinin CBAR kursu ilə AZN-ə çevrilir. Satıcıya ödəniş CBAR ilə (D × Y) göstərilir, bankın kursu ilə fərqi «Kurs fərqi» sətrindədir; valyuta alış-satışının bank ilə CBAR fərqi də oradadır (mənfi — qazanc). Satıcı və logistika köçürmələrinin komissiyası «Valyuta köçürmə komissiyası», digər bank komissiyaları «Ölkədaxili bank komissiyası» sətrindədir. Hesablar arası köçürmə və konvertasiya pul axını deyil.') }}
            </p>

            {{-- what a figure is made of --}}
            <div x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="open = null">
                <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = null"></div>
                <template x-if="open">
                    <div class="relative card w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden">
                        <header class="flex items-start justify-between gap-4 px-5 py-4 border-b border-line">
                            <div class="min-w-0">
                                <div class="text-[11px] uppercase tracking-wider text-muted" x-text="why[open].s"></div>
                                <h3 class="text-lg font-semibold" x-text="why[open].h"></h3>
                                <div class="text-sm text-ink-2 mt-0.5" x-show="why[open].f" x-text="why[open].f"></div>
                            </div>
                            <button type="button" class="btn btn-ghost btn-icon" @click="open = null" aria-label="{{ __('Bağla') }}"><x-icon name="x" class="size-5"/></button>
                        </header>
                        <div class="overflow-auto flex-1">
                            {{-- the money movements --}}
                            <template x-if="why[open].rows">
                                <table class="table-g text-[13px]">
                                    <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Əməliyyat') }}</th><th>{{ __('Hesablama') }}</th><th class="!text-right">AZN</th></tr></thead>
                                    <tbody>
                                        <template x-for="(r, i) in why[open].rows" :key="i">
                                            <tr>
                                                <td class="font-mono whitespace-nowrap text-muted" x-text="r.d"></td>
                                                <td><template x-if="r.u"><a :href="r.u" class="hover:text-brand-ink underline decoration-dotted underline-offset-2" x-text="r.t"></a></template><span x-show="!r.u" x-text="r.t"></span></td>
                                                <td class="font-mono text-[12px] text-muted" x-text="r.c || ''"></td>
                                                <td class="num font-semibold whitespace-nowrap" :class="why[open].fx && r.v < 0 ? 'text-success' : ''" x-text="glaustFmt.fmt(r.v, 2)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </template>
                            {{-- the lines of a total --}}
                            <template x-if="why[open].lines">
                                <table class="table-g text-[13px]">
                                    <tbody>
                                        <template x-for="(l, i) in why[open].lines" :key="i">
                                            <tr><td x-text="l.l"></td><td class="num font-semibold whitespace-nowrap" :class="l.v < 0 ? 'text-danger' : ''" x-text="(l.v < 0 ? '−' : '') + glaustFmt.fmt(Math.abs(l.v), 2)"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </template>
                        </div>
                        <footer class="flex items-center justify-between gap-3 px-5 py-3 border-t border-line bg-surface-2 text-sm">
                            <span class="text-muted"><span x-show="why[open].rows" x-text="(why[open].rows || []).length + ' {{ __('əməliyyat') }}'"></span> <span x-show="why[open].fx">· {{ __('mənfi — qazanc') }}</span></span>
                            <span class="font-semibold">{{ __('Cəmi') }}: <span class="font-mono" x-text="(why[open].v < 0 ? '−' : '') + glaustFmt.fmt(Math.abs(why[open].v), 2) + ' ₼'"></span></span>
                        </footer>
                    </div>
                </template>
            </div>
        </section>
    @endif
</x-layouts.app>
