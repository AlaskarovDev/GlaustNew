<x-layouts.app :title="__('İdarə paneli')">
@php
    $user = auth()->user();
    $tones = [
        'teal' => 'bg-brand-soft text-brand', 'rose' => 'bg-danger-soft text-danger', 'blue' => 'bg-blue-500/10 text-blue-600 dark:text-blue-300',
        'green' => 'bg-success-soft text-success', 'violet' => 'bg-violet-500/10 text-violet-600 dark:text-violet-300', 'amber' => 'bg-saffron-soft text-saffron',
    ];
    $span = [
        'kpis' => 'lg:col-span-12', 'shortcuts' => 'lg:col-span-12', 'cashflow' => 'lg:col-span-8', 'projects' => 'lg:col-span-4',
        'my_tasks' => 'lg:col-span-4', 'budget' => 'lg:col-span-8', 'balances' => 'lg:col-span-6', 'top' => 'lg:col-span-6',
        'logistics' => 'lg:col-span-4', 'rates' => 'lg:col-span-8', 'activity' => 'lg:col-span-12',
    ];
    $shortcuts = collect([
        [__('Yeni layihə'), 'folder', 'projects.create', [], 'projects.create', 'N P'],
        [__('Yeni müştəri'), 'user-plus', 'counterparties.create', ['type' => 'customer'], 'crm.create', 'N M'],
        [__('Yeni təchizatçı'), 'building', 'counterparties.create', ['type' => 'supplier'], 'crm.create', 'N S'],
        [__('Yeni müqavilə'), 'signature', 'contracts.create', [], 'contracts.create', 'N Q'],
        [__('Bank əməliyyatı'), 'bank', 'bank.transactions.create', [], 'bank.create', 'N B'],
        ['Excel import', 'upload', 'imports.index', [], null, null],
        [__('Hesabat yarat'), 'chart', 'reports.index', [], 'reports.view', 'G H'],
    ])->filter(fn ($s) => ! $s[4] || $user->can($s[4]));
    $charts = [
        'cashflow' => isset($cashflow) ? [
            'type' => 'bar', 'height' => 300, 'money' => true, 'categories' => $cashflow['categories'], 'colors' => ['#0f9d8a', '#e5484d', '#6366f1'],
            'series' => [
                ['name' => __('Daxilolma'), 'type' => 'column', 'data' => $cashflow['in']],
                ['name' => __('Məxaric'), 'type' => 'column', 'data' => $cashflow['out']],
                ['name' => __('Fərq'), 'type' => 'line', 'data' => $cashflow['net']],
            ],
            'stroke' => ['width' => [0, 0, 2.5], 'curve' => 'smooth'],
        ] : null,
        'projects' => isset($projectStatus) ? ['type' => 'donut', 'height' => 300, 'labels' => $projectStatus['labels'], 'series' => $projectStatus['series'], 'colors' => $projectStatus['colors'], 'totalLabel' => __('Layihə')] : null,
        'budget' => isset($budget) ? [
            'type' => 'bar', 'height' => 280, 'money' => true, 'categories' => $budget['categories'], 'colors' => ['#cbd5e1', '#0f9d8a'], 'columnWidth' => '58%',
            'series' => [['name' => __('Büdcə'), 'data' => $budget['budget']], ['name' => __('Faktiki'), 'data' => $budget['actual']]],
        ] : null,
        'top' => isset($top) ? [
            'type' => 'bar', 'horizontal' => true, 'height' => 260, 'money' => true, 'categories' => $top['labels'], 'colors' => ['#6366f1'],
            'series' => [['name' => __('Dövriyyə'), 'data' => $top['series']]],
            'xaxis' => ['labels' => ['show' => false]], 'yaxis' => ['labels' => ['maxWidth' => 180, 'style' => ['fontSize' => '12px']]],
        ] : null,
    ];
    $auditVerbs = ['created' => __('yaratdı'), 'updated' => __('dəyişdi'), 'deleted' => 'sildi', 'restored' => __('bərpa etdi')];
    $auditTypes = ['project' => __('layihə'), 'task' => __('tapşırıq'), 'contract' => __('müqavilə'), 'counterparty' => 'kontragent', 'bank_transaction' => __('bank əməliyyatı'),
        'bank_account' => __('bank hesabı'), 'shipment' => __('yük'), 'shipment_cost' => __('logistika xərci'), 'user' => __('istifadəçi'), 'role' => 'rol', 'company' => __('şirkət')];
@endphp

<div x-data="dashboardLayout(@js(['order' => $order, 'hidden' => $hidden]), @js($widgets))">
    {{-- Greeting --}}
    <div class="mb-7 flex flex-col gap-4 md:flex-row md:items-end md:justify-between rise">
        <div>
            <p class="text-sm font-medium text-brand-ink">{{ az_weekday($today) }}, {{ $today->day }} {{ az_month($today->month) }} {{ $today->year }}</p>
            <h1 class="mt-1 text-[28px] lg:text-[32px] font-semibold tracking-tight">{{ $greeting }}, {{ explode(' ', $user->name)[0] }}</h1>
            <p class="mt-1 text-sm text-muted">
                @if($myDay['counts']['tasks'] || $myDay['counts']['reminders'])
                    {{ __('Bu gün') }} <span class="font-semibold text-ink">{{ $myDay['counts']['tasks'] }}</span> {{ __('tapşırığınız') }}
                    @if($myDay['counts']['overdue'])(<span class="text-danger font-medium">{{ $myDay['counts']['overdue'] }} {{ __('gecikmiş') }}</span>)@endif
                    {{ __('və') }} <span class="font-semibold text-ink">{{ $myDay['counts']['reminders'] }}</span> {{ __('xatırlatmanız var.') }}
                @else
                    {{ __('Bu gün üçün təcili iş yoxdur — :company üzrə ümumi vəziyyət aşağıdadır.', ['company' => $company->name]) }}
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-secondary" @click="editing = !editing" :class="editing && '!border-brand !text-brand-ink'">
                <x-icon name="layers" class="size-4"/> <span x-text="editing ? {{ \Illuminate\Support\Js::from(__('Bağla')) }} : {{ \Illuminate\Support\Js::from(__('Paneli düzənlə')) }}"></span>
            </button>
        </div>
    </div>

    {{-- Layout editor --}}
    <div x-cloak x-show="editing" x-collapse>
        <div class="card p-4 mb-6">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm font-semibold">{{ __('Vidjetlər') }} <span class="text-muted font-normal">{{ __('— göstər/gizlət və sırasını dəyiş') }}</span></div>
                <button type="button" class="btn btn-primary btn-sm" @click="save()"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
            </div>
            <ul class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
                <template x-for="key in order" :key="key">
                    <li class="flex items-center gap-2 h-11 px-3 rounded-lg border border-line bg-surface-2">
                        <input type="checkbox" class="checkbox" :checked="visible(key)" @change="toggle(key)" :id="'w-' + key">
                        <label :for="'w-' + key" class="text-sm flex-1 truncate cursor-pointer" x-text="all[key]"></label>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon !size-7" @click="move(key, -1)" aria-label="{{ __('Yuxarı') }}"><x-icon name="chevron-up" class="size-4"/></button>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon !size-7" @click="move(key, 1)" aria-label="{{ __('Aşağı') }}"><x-icon name="chevron-down" class="size-4"/></button>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 stagger">

        {{-- KPIs --}}
        <section class="{{ $span['kpis'] }}" x-show="visible('kpis')" :style="{ order: pos('kpis') }" style="--i:0" aria-label="{{ __('Əsas göstəricilər') }}">
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6 gap-4">
                @foreach($kpis as $i => $kpi)
                    <a href="{{ $kpi['url'] }}" class="card card-hover p-5 group rise" style="--i:{{ $i }}">
                        <div class="flex items-start justify-between">
                            <span class="grid place-items-center size-10 rounded-xl {{ $tones[$kpi['tone']] }}"><x-icon :name="$kpi['icon']" class="size-5"/></span>
                            <x-icon name="arrow-up-right" class="size-4 text-faint opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all"/>
                        </div>
                        <div class="mt-4 text-[13px] text-muted">{{ $kpi['label'] }}</div>
                        <div class="mt-1 text-[26px] leading-none font-semibold font-mono tabular tracking-tight text-ink">
                            @if(!empty($kpi['money']))
                                <span x-countup="{{ $kpi['value'] }}" data-decimals="0" data-suffix=" ₼">{{ money($kpi['value']) }}</span>
                            @else
                                <span x-countup.int="{{ $kpi['value'] }}">{{ $kpi['value'] }}</span>
                            @endif
                        </div>
                        <div class="mt-2 text-xs text-muted truncate">{{ $kpi['hint'] }}</div>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- Shortcuts --}}
        <section class="{{ $span['shortcuts'] }}" x-show="visible('shortcuts')" :style="{ order: pos('shortcuts') }" style="--i:1" aria-label="{{ __('Qısa yollar') }}">
            <div class="card p-2.5">
                <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-1.5">
                    @foreach($shortcuts as [$label, $icon, $route, $params, $ability, $keys])
                        <a href="{{ route($route, $params) }}" class="group flex flex-col items-center justify-center gap-2 h-[88px] rounded-[11px] text-center hover:bg-brand-soft transition-colors">
                            <span class="grid place-items-center size-9 rounded-lg bg-surface-2 border border-line text-ink-2 group-hover:text-brand group-hover:border-brand/30 group-hover:scale-110 transition-all">
                                <x-icon :name="$icon" class="size-[18px]"/>
                            </span>
                            <span class="text-[13px] font-medium text-ink-2 group-hover:text-brand-ink leading-tight">{{ $label }}</span>
                            @if($keys)<span class="hidden xl:block text-[10px] font-mono text-faint -mt-1">{{ $keys }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Cashflow --}}
        @isset($cashflow)
        <section class="{{ $span['cashflow'] }} card p-5 lg:p-6" x-show="visible('cashflow')" :style="{ order: pos('cashflow') }" style="--i:2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ __('Gəlir və xərc') }}</h2>
                    <p class="text-xs text-muted mt-0.5">{{ __('Son 12 ay · bank əməliyyatları CBAR məzənnəsi ilə AZN-də · daxili köçürmələr daxil deyil') }}</p>
                </div>
                <div class="flex gap-5 text-right">
                    <div><div class="text-[11px] text-muted uppercase tracking-wider">{{ __('Daxilolma') }}</div><div class="font-mono font-semibold text-success">{{ money($cashflow['totalIn']) }}</div></div>
                    <div><div class="text-[11px] text-muted uppercase tracking-wider">{{ __('Məxaric') }}</div><div class="font-mono font-semibold text-danger">{{ money($cashflow['totalOut']) }}</div></div>
                </div>
            </div>
            <div class="mt-4 -mx-2 h-[300px]" x-chart="{{ json_encode($charts['cashflow']) }}"><div class="skeleton h-full mx-2"></div></div>
        </section>
        @endisset

        {{-- Project status --}}
        @isset($projectStatus)
        <section class="{{ $span['projects'] }} card p-5 lg:p-6" x-show="visible('projects')" :style="{ order: pos('projects') }" style="--i:3">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ __('Layihələrin statusu') }}</h2>
                <a href="{{ route('projects.index') }}" class="text-xs font-medium text-brand-ink hover:underline">{{ __('Hamısı') }}</a>
            </div>
            @if($projectStatus['total'])
                <div class="mt-2 h-[300px]" x-chart="{{ json_encode($charts['projects']) }}">
                    <div class="skeleton size-48 rounded-full mx-auto mt-8"></div>
                </div>
            @else
                <x-empty icon="folder" :title="__('Layihə yoxdur')" :text="__('İlk layihənizi yaradın.')">@can('projects.create')<a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/> {{ __('Layihə') }}</a>@endcan</x-empty>
            @endif
        </section>
        @endisset

        {{-- My tasks --}}
        <section class="{{ $span['my_tasks'] }} card p-5 lg:p-6 flex flex-col" x-show="visible('my_tasks')" :style="{ order: pos('my_tasks') }" style="--i:4">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ __('Bugünkü işlərim') }}</h2>
                <a href="{{ route('my-work') }}" class="text-xs font-medium text-brand-ink hover:underline">{{ __('Hamısı') }}</a>
            </div>
            @php $list = collect($myDay['tasks']['overdue'])->map(fn ($t) => $t + ['late' => true])->merge($myDay['tasks']['today'])->take(7); @endphp
            @if($list->isEmpty())
                <x-empty icon="check-circle" :title="__('Bu gün üçün tapşırıq yoxdur')" class="!py-8 flex-1"/>
            @else
                <ul class="mt-3 -mx-2 flex-1">
                    @foreach($list as $t)
                        <li>
                            <a href="{{ $t['url'] }}" class="flex items-center gap-3 px-2 py-2.5 rounded-lg hover:bg-surface-2 transition-colors">
                                <span @class(['size-2 rounded-full shrink-0', 'bg-danger' => !empty($t['late']), 'bg-saffron' => empty($t['late'])])></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium truncate">{{ $t['title'] }}</span>
                                    <span class="block text-xs text-muted truncate">{{ $t['project'] ?? __('Layihəsiz') }}</span>
                                </span>
                                <span @class(['text-xs font-mono shrink-0', 'text-danger' => !empty($t['late']), 'text-muted' => empty($t['late'])])>{{ $t['due'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Budget vs actual --}}
        @isset($budget)
        <section class="{{ $span['budget'] }} card p-5 lg:p-6" x-show="visible('budget')" :style="{ order: pos('budget') }" style="--i:5">
            <h2 class="text-base font-semibold">{{ __('Büdcə və faktiki xərc') }}</h2>
            <p class="text-xs text-muted mt-0.5">{{ __('Aktiv layihələr · AZN · faktiki = layihəyə bağlı bank məxarici + logistika xərcləri') }}</p>
            @if(count($budget['categories']))
                <div class="mt-4 -mx-2 h-[280px]" x-chart="{{ json_encode($charts['budget']) }}"><div class="skeleton h-full mx-2"></div></div>
            @else
                <x-empty icon="target" :title="__('Büdcəli aktiv layihə yoxdur')" class="!py-10"/>
            @endif
            @if($budget['skipped'])
                <p class="mt-2 text-xs text-saffron">{{ __('Məzənnə tapılmadığı üçün göstərilmədi:') }} {{ implode(', ', $budget['skipped']) }}</p>
            @endif
        </section>
        @endisset

        {{-- Bank balances --}}
        @isset($balances)
        <section class="{{ $span['balances'] }} card p-5 lg:p-6" x-show="visible('balances')" :style="{ order: pos('balances') }" style="--i:6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ __('Bank hesabları') }}</h2>
                    <p class="text-xs text-muted mt-0.5">{{ __('Qalıqlar və bugünkü CBAR məzənnəsi ilə AZN ekvivalenti') }}</p>
                </div>
                <div class="text-right">
                    <div class="text-[11px] text-muted uppercase tracking-wider">{{ __('Cəmi') }}</div>
                    <div class="font-mono font-semibold text-lg"><span x-countup="{{ $balances['total'] }}" data-decimals="2" data-suffix=" ₼">{{ money($balances['total']) }}</span></div>
                </div>
            </div>
            @if(count($balances['items']))
                <ul class="mt-4 space-y-3">
                    @php $max = max(1, collect($balances['items'])->max(fn ($i) => abs($i['azn'] ?? 0))); @endphp
                    @foreach($balances['items'] as $b)
                        <li>
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="min-w-0 truncate"><span class="font-medium">{{ $b['name'] }}</span> <span class="text-muted">· {{ $b['bank'] }}</span></span>
                                <span class="font-mono tabular shrink-0 {{ $b['balance'] < 0 ? 'text-danger' : '' }}">{{ money($b['balance'], $b['currency']) }}</span>
                            </div>
                            <div class="mt-1.5 flex items-center gap-3">
                                <div class="h-1.5 flex-1 rounded-full bg-surface-2 overflow-hidden">
                                    <div class="h-full rounded-full bg-brand origin-left rise" style="width: {{ $b['azn'] !== null ? max(2, abs($b['azn']) / $max * 100) : 0 }}%"></div>
                                </div>
                                <span class="w-28 text-right text-xs font-mono text-muted">{{ $b['azn'] !== null ? '≈ '.money($b['azn']) : __('məzənnə yoxdur') }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <x-empty icon="bank" :title="__('Bank hesabı əlavə edilməyib')" class="!py-10">@can('bank.create')<a href="{{ route('bank.accounts.create') }}" class="btn btn-secondary btn-sm">{{ __('Hesab əlavə et') }}</a>@endcan</x-empty>
            @endif
        </section>
        @endisset

        {{-- Top counterparties --}}
        @isset($top)
        <section class="{{ $span['top'] }} card p-5 lg:p-6" x-show="visible('top')" :style="{ order: pos('top') }" style="--i:7">
            <h2 class="text-base font-semibold">{{ __('TOP-5 kontragent') }}</h2>
            <p class="text-xs text-muted mt-0.5">{{ __('Son 12 ayda dövriyyə (daxilolma + məxaric), AZN') }}</p>
            @if(count($top['labels']))
                <div class="mt-3 -mx-2 h-[260px]" x-chart="{{ json_encode($charts['top']) }}"><div class="skeleton h-full mx-2"></div></div>
            @else
                <x-empty icon="users" :title="__('Hələ dövriyyə yoxdur')" :text="__('Bank əməliyyatlarını kontragentlə bağladıqca burada görünəcək.')" class="!py-10"/>
            @endif
        </section>
        @endisset

        {{-- Logistics --}}
        @isset($logistics)
        <section class="{{ $span['logistics'] }} card p-5 lg:p-6" x-show="visible('logistics')" :style="{ order: pos('logistics') }" style="--i:8">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">{{ __('Logistika') }}</h2>
                <a href="{{ route('shipments.index') }}" class="text-xs font-medium text-brand-ink hover:underline">{{ __('Yüklər') }}</a>
            </div>
            @php $totalShip = array_sum($logistics); @endphp
            <ul class="mt-4 space-y-2.5">
                @foreach(config('glaust.statuses.shipment') as $key => [$label, $color])
                    @php $n = $logistics[$key] ?? 0; @endphp
                    <li>
                        <a href="{{ route('shipments.index', ['status' => $key]) }}" class="flex items-center gap-3 text-sm group">
                            <span class="w-28 shrink-0 text-ink-2 group-hover:text-ink">{{ $label }}</span>
                            <span class="h-2 flex-1 rounded-full bg-surface-2 overflow-hidden">
                                <span class="block h-full rounded-full badge-{{ $color }} !bg-current opacity-80" style="width: {{ $totalShip ? max($n ? 4 : 0, $n / $totalShip * 100) : 0 }}%"></span>
                            </span>
                            <span class="w-8 text-right font-mono text-ink">{{ $n }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
        @endisset

        {{-- Rates --}}
        <section class="{{ $span['rates'] }} card p-5 lg:p-6" x-show="visible('rates')" :style="{ order: pos('rates') }" style="--i:9">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">{{ __('Məzənnə dinamikası') }}</h2>
                    <p class="text-xs text-muted mt-0.5">{{ __('Son 30 gün · Azərbaycan Respublikasının Mərkəzi Bankı · 1 vahid üçün AZN') }}</p>
                </div>
                <a href="{{ route('currency.index') }}" class="text-xs font-medium text-brand-ink hover:underline">{{ __('Bütün valyutalar') }}</a>
            </div>
            <div class="mt-4 grid sm:grid-cols-3 gap-3">
                @foreach($rateHistory as $code => $points)
                    @php
                        $vals = array_values($points);
                        $last = end($vals) ?: null; $first = $vals[0] ?? null;
                        $chg = ($first && $last) ? ($last - $first) / $first * 100 : null;
                    @endphp
                    <div class="rounded-xl border border-line p-4 bg-surface-2/50">
                        <div class="flex items-baseline justify-between">
                            <span class="text-sm font-semibold">{{ $code }}</span>
                            @if($chg !== null)
                                <span class="text-xs font-mono {{ $chg > 0.005 ? 'text-success' : ($chg < -0.005 ? 'text-danger' : 'text-faint') }}">{{ $chg > 0 ? '+' : '' }}{{ num($chg) }}%</span>
                            @endif
                        </div>
                        <div class="mt-1 text-xl font-mono font-semibold tabular">{{ $last ? rate_fmt($last) : '—' }}</div>
                        @if(count($vals) > 1)
                            @php
                                $spark = ['type' => 'area', 'height' => 64, 'sparkline' => true, 'rate' => true, 'colors' => [$chg !== null && $chg < 0 ? '#e5484d' : '#0f9d8a'],
                                    'series' => [['name' => $code, 'data' => $vals]], 'labels' => array_map(fn ($d) => \Carbon\Carbon::parse($d)->format('d.m'), array_keys($points))];
                            @endphp
                            <div class="mt-2 h-[64px] -mx-1" x-chart="{{ json_encode($spark) }}"></div>
                        @else
                            <div class="mt-2 h-[64px] grid place-items-center text-xs text-muted">{{ __('Tarixçə toplanır…') }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Activity --}}
        <section class="{{ $span['activity'] }} card" x-show="visible('activity')" :style="{ order: pos('activity') }" style="--i:10">
            <div class="flex items-center justify-between px-5 lg:px-6 pt-5">
                <h2 class="text-base font-semibold">{{ __('Son fəaliyyətlər') }}</h2>
                @can('logs.view')<a href="{{ route('settings.logs.audit') }}" class="text-xs font-medium text-brand-ink hover:underline">{{ __('Audit jurnalı') }}</a>@endcan
            </div>
            @if($activity->isEmpty())
                <x-empty icon="history" :title="__('Hələ fəaliyyət yoxdur')" class="!py-10"/>
            @else
                <ol class="px-5 lg:px-6 py-4 grid md:grid-cols-2 gap-x-10">
                    @foreach($activity as $a)
                        <li class="relative flex gap-3 py-2.5 border-b border-line/70 last:border-0 md:[&:nth-last-child(2)]:border-0">
                            <x-avatar :user="$a->user" size="sm"/>
                            <div class="min-w-0 text-sm">
                                <span class="font-medium text-ink">{{ $a->user?->name ?? 'Sistem' }}</span>
                                <span class="text-muted">{{ $auditTypes[$a->auditable_type] ?? $a->auditable_type }} {{ $auditVerbs[$a->action] ?? $a->action }}:</span>
                                <span class="text-ink-2 break-words">{{ $a->label }}</span>
                                <div class="text-xs text-faint mt-0.5">{{ $a->created_at->diffForHumans() }}</div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</div>
</x-layouts.app>
