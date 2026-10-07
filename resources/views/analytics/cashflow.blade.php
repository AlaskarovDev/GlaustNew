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

        <section class="card overflow-hidden">
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
                            @foreach($months as $m)<td @class(['px-4 py-2.5 text-right font-mono tabular-nums', 'text-danger' => $data['opening'][$m] < 0])>{{ $n($data['opening'][$m]) }}</td>@endforeach
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ $n($openFirst) }}</td>
                        </tr>

                        {{-- money in --}}
                        <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 pt-5 pb-2 text-xs font-bold uppercase tracking-wider text-success"><span class="inline-flex items-center gap-1.5"><x-icon name="arrow-down-left" class="size-4"/> {{ __('Gəlirlər') }}</span></td></tr>
                        @forelse($data['income'] as $g)
                            <tr class="group">
                                <td class="sticky left-0 z-10 bg-surface group-hover:bg-surface-2 px-4 py-2 border-b border-line">
                                    @if($g['url'])<a href="{{ $g['url'] }}" class="hover:text-brand-ink">{{ $g['label'] }}</a>@else{{ $g['label'] }}@endif
                                </td>
                                @foreach($months as $m)<td @class(['px-4 py-2 text-right font-mono tabular-nums border-b border-line group-hover:bg-surface-2', 'text-faint' => abs($g['values'][$m]) < 0.005])>{{ $n($g['values'][$m]) }}</td>@endforeach
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold border-b border-line bg-surface-2/60 group-hover:bg-surface-2">{{ $n($g['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 py-2 text-muted text-xs">{{ __('Daxilolma yoxdur') }}</td></tr>
                        @endforelse
                        <tr class="bg-success-soft font-semibold">
                            <td class="sticky left-0 z-10 bg-success-soft px-4 py-2.5">{{ __('Cəmi gəlirlər') }}</td>
                            @foreach($months as $m)<td class="px-4 py-2.5 text-right font-mono tabular-nums text-success">{{ $n($data['in'][$m]) }}</td>@endforeach
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums text-success">{{ $n($sumIn) }}</td>
                        </tr>

                        {{-- money out --}}
                        <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 pt-6 pb-2 text-xs font-bold uppercase tracking-wider text-danger"><span class="inline-flex items-center gap-1.5"><x-icon name="arrow-up-right" class="size-4"/> {{ __('Ödənişlər') }}</span></td></tr>
                        @forelse($data['payments'] as $g)
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
                                    <td @class(['px-4 py-2 text-right font-mono tabular-nums border-b border-line group-hover:bg-surface-2', 'text-faint' => abs($v) < 0.005, 'text-success' => $v < -0.005])
                                        @if($g['kind'] === 'fx' && $v < -0.005) title="{{ __('qazanc') }}" @endif>{{ $n($v) }}</td>
                                @endforeach
                                <td class="px-4 py-2 text-right font-mono tabular-nums font-semibold border-b border-line bg-surface-2/60 group-hover:bg-surface-2">{{ $n($g['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($months) + 2 }}" class="sticky left-0 px-4 py-2 text-muted text-xs">{{ __('Ödəniş yoxdur') }}</td></tr>
                        @endforelse
                        <tr class="bg-danger-soft font-semibold">
                            <td class="sticky left-0 z-10 bg-danger-soft px-4 py-2.5">{{ __('Cəmi ödənişlər') }}</td>
                            @foreach($months as $m)<td class="px-4 py-2.5 text-right font-mono tabular-nums text-danger">{{ $n($data['out'][$m]) }}</td>@endforeach
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums text-danger">{{ $n($sumOut) }}</td>
                        </tr>

                        {{-- result --}}
                        <tr><td colspan="{{ count($months) + 2 }}" class="h-4"></td></tr>
                        <tr class="font-semibold">
                            <td class="sticky left-0 z-10 bg-surface px-4 py-2.5 border-y border-line">{{ __('Xalis pul axını') }}</td>
                            @foreach($months as $m)<td @class(['px-4 py-2.5 text-right font-mono tabular-nums border-y border-line', 'text-success' => $data['net'][$m] > 0.005, 'text-danger' => $data['net'][$m] < -0.005])>{{ $data['net'][$m] > 0.005 ? '+' : '' }}{{ $n($data['net'][$m]) }}</td>@endforeach
                            <td @class(['px-4 py-2.5 text-right font-mono tabular-nums border-y border-line', 'text-success' => $sumIn - $sumOut > 0, 'text-danger' => $sumIn - $sumOut < 0])>{{ $n($sumIn - $sumOut) }}</td>
                        </tr>
                        <tr class="bg-[#9a4a0c]/10 font-bold">
                            <td class="sticky left-0 z-10 bg-[#f6ebe2] dark:bg-[#3a2416] px-4 py-3">{{ __('Dövrün sonuna qalıq') }}</td>
                            @foreach($months as $m)<td @class(['px-4 py-3 text-right font-mono tabular-nums', 'text-danger' => $data['closing'][$m] < 0])>{{ $n($data['closing'][$m]) }}</td>@endforeach
                            <td @class(['px-4 py-3 text-right font-mono tabular-nums', 'text-danger' => $closeLast < 0])>{{ $n($closeLast) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="px-5 py-3 border-t border-line text-[11px] text-muted leading-relaxed">
                {{ __('Daxilolmalar və ödənişlər öz tarixinin CBAR kursu ilə AZN-ə çevrilir. Satıcıya ödəniş CBAR ilə (D × Y) göstərilir, bankın kursu ilə fərqi «Kurs fərqi» sətrindədir; valyuta alış-satışının bank ilə CBAR fərqi də oradadır (mənfi — qazanc). Satıcı və logistika köçürmələrinin komissiyası «Valyuta köçürmə komissiyası», digər bank komissiyaları «Ölkədaxili bank komissiyası» sətrindədir. Hesablar arası köçürmə və konvertasiya pul axını deyil.') }}
            </p>
        </section>
    @endif
</x-layouts.app>
