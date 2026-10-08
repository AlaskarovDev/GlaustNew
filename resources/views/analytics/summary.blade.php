<x-layouts.app :title="$title" wide>
    <x-page-header :title="$title" :icon="$icon" :subtitle="__('Satıcı fakturası, alıcı fakturası, kurslar, logistika və nəticə — hər Trade üzrə, şirkətin ATF cədvəlinin qaydası ilə')">
        <x-slot:actions>
            @if($rows)
                <a href="{{ route('analytics.show', array_filter(['report' => 'summary', 'project_id' => $projectId, 'deal_id' => $dealId, 'as_month' => $asOf ? substr($asOf, 0, 7) : null, 'format' => 'xlsx'])) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <nav class="flex gap-2 overflow-x-auto pb-1 mb-6" aria-label="{{ __('Hesabatlar') }}">
        @foreach($all as $key => [$label, $ico])
            <a href="{{ route('analytics.show', $key) }}" @class(['badge !h-9 !px-3.5 !text-[13px] gap-1.5 shrink-0', 'badge-teal' => $key === $report, 'badge-slate hover:!text-ink' => $key !== $report]) @if($key === $report) aria-current="page" @endif>
                <x-icon :name="$ico" class="size-4"/> {{ __($label) }}
            </a>
        @endforeach
    </nav>

    @php
        $tone = [
            'slate' => 'bg-surface-2 text-ink-2', 'amber' => 'bg-saffron-soft text-ink', 'green' => 'bg-success-soft text-success',
            'teal' => 'bg-brand-soft text-brand-ink', 'blue' => 'bg-[color-mix(in_srgb,#3b82f6_12%,transparent)] text-[#2563eb]', 'rose' => 'bg-danger-soft text-danger',
        ];
        $key = ['FP', 'AQ', 'BE', 'BT', 'C1'];           // the results, highlighted
        $byGroup = collect($columns)->groupBy('g', true);
        $rateDays = \App\Support\Reports\TradeReport::rateDays();
        $meta = collect($columns)->map(fn ($c, $k) => [
            'l' => $c['l'], 'g' => $groups[$c['g']][0], 'f' => $c['f'], 'note' => $c['note'], 'ref' => $c['ref'],
            'in' => collect($c['in'])->map(fn ($i) => ['k' => $i, 'l' => $columns[$i]['l'], 'ref' => $columns[$i]['ref'], 'day' => $rateDays[$i] ?? null])->values(),
            'onec' => $c['g'] === 'onec', 'key' => $k,
        ]);
        $sum = fn ($k) => $totals[$k] ?? null;
        $trades = collect($rows)->pluck('deal.id')->unique()->count();
        $fx = ($sum('AL') ?? 0) + ($sum('AM') ?? 0);
    @endphp

    {{-- choose the project / Trade --}}
    <form method="GET" action="{{ route('analytics.show', 'summary') }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3"
          x-data="{ project: @js((string) ($projectId ?? '')), deal: @js((string) ($dealId ?? '')), deals: @js($dealList->map(fn ($d) => ['id' => $d->id, 'p' => $d->project_id, 'label' => $d->code.' · '.($d->supplier?->name ?? '—').' → '.($d->counterparty?->name ?? '—')])) }">
        <label class="min-w-[240px] flex-1">
            <span class="field-label">{{ __('Layihə') }}</span>
            <select name="project_id" class="input" x-model="project" @change="deal = ''">
                <option value="">{{ __('Bütün layihələr') }}</option>
                @foreach($projects as $p)<option value="{{ $p->id }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach
            </select>
        </label>
        <label class="min-w-[260px] flex-1">
            <span class="field-label">Trade</span>
            <select name="deal_id" class="input" x-model="deal">
                <option value="">{{ __('Bütün Trade-lər') }}</option>
                <template x-for="d in deals.filter(d => !project || String(d.p) === project)" :key="d.id"><option :value="String(d.id)" x-text="d.label" :selected="String(d.id) === deal"></option></template>
            </select>
        </label>
        <label class="min-w-[200px]">
            <span class="field-label">{{ __('Bitməyən Trade-lər') }}</span>
            <input type="month" name="as_month" value="{{ $month }}" max="{{ $lastMonth }}" class="input font-mono @if($monthError) is-invalid @endif" aria-describedby="as-month-hint">
            <span id="as-month-hint" class="block text-[11px] text-muted mt-1">{{ __('ay və il — kurs ayın son gününə götürülür; boş: akt olanda') }}</span>
        </label>
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        <button name="calc" value="1" class="btn btn-secondary" title="{{ __('Seçilən ayın (seçilməyibsə — son bitmiş ayın) son gününün CBAR kursu ilə') }}"><x-icon name="calendar" class="size-4"/> {{ __('Ay sonuna görə hesabla') }}</button>
        @if($projectId || $dealId || $asOf)<a href="{{ route('analytics.show', 'summary') }}" class="btn btn-ghost">{{ __('Sıfırla') }}</a>@endif
    </form>

    @if($monthError)
        <div class="card border-danger/30 bg-danger-soft/40 p-4 mb-6 flex items-center gap-3 text-sm text-danger" role="alert"><x-icon name="alert" class="size-5 shrink-0"/> {{ $monthError }}</div>
    @endif
    @if($asOf)
        <div class="card border-saffron/40 bg-saffron-soft/50 p-4 mb-6 flex items-start gap-3 text-sm">
            <x-icon name="calendar" class="size-5 text-saffron shrink-0"/>
            <div><span class="font-semibold">{{ __(':v1 ay sonuna görə', ['v1' => azdate($asOf)]) }}</span> —
                {{ __('aktı olmayan (və ya aktı ay sonundan sonra olan) Trade-lər bu günün CBAR kursu ilə qiymətləndirilir: ay sonu akt tarixinin yerinə keçir, ondan sonrakı ödənişlər hələ baş verməmiş sayılır. Belə sətirlər «ay sonu» nişanı ilə göstərilir.') }}
                {{ __('Məzənnə fərqi 1C metodu ilə 1 yanvar → ay sonu dövrü üçün hesablanır; maddələr üzrə və seçilmiş ayın sütunları əlavə olundu.') }}</div>
        </div>
    @endif

    @if(! $rows)
        <div class="card"><x-empty icon="layers" :title="__('Hesablanacaq faktura yoxdur')" :text="__('Seçilən layihədə / Trade-də satıcı fakturası daxil edildikdən sonra hesabat burada avtomatik qurulur.')"/></div>
    @else
        {{-- the results at a glance --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5 gap-4 mb-6 stagger">
            @foreach([
                ['FP', __('Proqnoz mənfəət'), __('hesab verilən gün, proqnoz kurslarla'), 'target'],
                ['AQ', __('Xalis mənfəət — pul axını'), __('satış − alış − logistika − komissiyalar'), 'trending-up'],
                ['BE', __('Gəlir — kurs fərqləri ilə'), __('bank məzənnə fərqləri çıxılmaqla'), 'coins'],
            ] as $i => [$k, $label, $hint, $ico])
                @php $v = $sum($k); @endphp
                <div class="card p-5" style="--i:{{ $i }}">
                    <div class="flex items-center gap-2 text-xs text-muted"><x-icon :name="$ico" class="size-4"/> {{ $label }}</div>
                    <div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-success' => $v > 0, 'text-danger' => $v < 0, 'text-faint' => $v === null])>{{ $v === null ? '—' : money($v) }}</div>
                    <div class="text-[11px] text-faint mt-0.5">{{ $hint }}</div>
                </div>
            @endforeach
            <div class="card p-5" style="--i:3">
                <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="transfer" class="size-4"/> {{ __('Bank məzənnə fərqləri') }}</div>
                <div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-danger' => $fx > 0, 'text-success' => $fx < 0])>{{ $fx > 0 ? '−' : ($fx < 0 ? '+' : '') }}{{ money(abs($fx)) }}</div>
                <div class="text-[11px] text-faint mt-0.5">{{ count($rows) }} {{ __('faktura') }} · {{ $trades }} Trade</div>
            </div>
            <div class="card p-5" style="--i:4">
                <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="scale" class="size-4"/> {{ __('Məzənnə fərqi — 214 / 219.3') }}</div>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-3 font-mono font-semibold tabular-nums">
                    <span class="text-lg text-success" title="{{ __('Müsbət məzənnə fərqi') }} · 214">+{{ money($sum('C1_P') ?? 0) }}</span>
                    <span class="text-lg text-danger" title="{{ __('Mənfi məzənnə fərqi') }} · 219.3">−{{ money($sum('C1_N') ?? 0) }}</span>
                </div>
                <div class="text-[11px] text-faint mt-0.5">{{ $oneC ? __('1C metodu: 1 yanvar → :v1', ['v1' => azdate($oneC)]) : __('1C metodu: Trade-lərin bütün müddəti') }}</div>
            </div>
        </div>

        <section class="card overflow-hidden" x-data="{
                hidden: {}, open: null,
                meta: @js($meta), cells: @js($cells),
                show(k) { this.open = k; },
             }" x-init="const c = new URLSearchParams(location.search).get('col'); if (c && meta[c]) open = c">
            <header class="px-5 py-3 border-b border-line flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ __('Hesabat cədvəli') }}</h2>
                    <p class="text-xs text-muted">{{ __('Sütun adına toxunun — onun necə hesablandığı açılacaq. Boş xana: məlumat hələ yoxdur (ödəniş, akt və ya kurs).') }}</p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($groups as $g => [$gl, $gt])
                        <button type="button" class="badge !h-7 cursor-pointer select-none" :class="hidden[@js($g)] ? 'badge-slate opacity-50 line-through' : 'badge-{{ $gt }}'" @click="hidden[@js($g)] = !hidden[@js($g)]">{{ $gl }}</button>
                    @endforeach
                </div>
            </header>

            <div class="overflow-auto max-h-[75vh]">
                <table class="text-[13px] border-separate border-spacing-0 min-w-full">
                    <thead class="sticky top-0 z-20">
                        <tr>
                            @foreach($byGroup as $g => $cols)
                                <th colspan="{{ $cols->count() }}" x-show="!hidden[@js($g)]"
                                    @class(['px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wider border-b border-r border-line whitespace-nowrap', $tone[$groups[$g][1]]])>{{ $groups[$g][0] }}</th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($columns as $k => $c)
                                <th x-show="!hidden[@js($c['g'])]" @class(['p-0 border-b border-r border-line bg-surface align-bottom', 'sticky z-30' => in_array($k, ['no', 'seller_no']), 'left-0' => $k === 'no', 'left-[44px]' => $k === 'seller_no'])>
                                    <button type="button" @click="show(@js($k))" @class(['group w-full h-full px-3 py-2 text-left hover:bg-surface-2 focus-visible:bg-surface-2 outline-none', 'min-w-[44px]' => $k === 'no', 'min-w-[120px]' => $k !== 'no'])>
                                        <span @class(['block text-[12px] font-semibold leading-tight', 'text-brand-ink' => in_array($k, $key), 'text-ink' => ! in_array($k, $key)])>{{ $c['l'] }}</span>
                                        <span class="mt-0.5 flex items-center gap-1 text-[10px] text-faint font-normal">
                                            @if($c['ref'])<span class="font-mono">{{ $c['ref'] }}</span>@endif
                                            @if($c['f'])<span class="font-mono truncate max-w-[140px]">= {{ $c['f'] }}</span>@endif
                                            <x-icon name="info" class="size-3 opacity-0 group-hover:opacity-100 shrink-0"/>
                                        </span>
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr class="group/row">
                                @foreach($columns as $k => $c)
                                    @php
                                        $v = $r[$k] ?? null;
                                        $num = in_array($c['t'], ['azn', 'rate'], true) || str_starts_with($c['t'], 'cur:');
                                        $neg = $c['t'] === 'azn' && $v !== null && $v < -0.005;
                                    @endphp
                                    <td x-show="!hidden[@js($c['g'])]" @class([
                                        'px-3 py-2.5 border-b border-r border-line whitespace-nowrap bg-surface group-hover/row:bg-surface-2',
                                        'text-right font-mono tabular-nums' => $num, 'text-faint' => $v === null,
                                        'text-danger' => $neg, 'font-semibold bg-brand-soft/30' => in_array($k, $key),
                                        'sticky z-10' => in_array($k, ['no', 'seller_no']), 'left-0' => $k === 'no', 'left-[44px]' => $k === 'seller_no',
                                    ])>
                                        @if($k === 'seller_no')
                                            <a href="{{ route('invoices.show', $r['invoice']) }}" class="font-mono font-medium text-ink hover:text-brand-ink">{{ $v }}</a>
                                            <a href="{{ route('deals.show', $r['deal']) }}" class="block text-[10px] text-muted hover:text-brand-ink">Trade {{ $r['deal']->code }}</a>
                                            @if($r['provisional'])<span class="badge badge-amber !text-[10px] !h-5 mt-0.5">{{ __('ay sonu') }}</span>@endif
                                        @elseif($k === 'BI' && $r['provisional'])
                                            {{ $fmt($r, $k) }} <span class="badge badge-amber !text-[10px] !h-5">{{ __('ay sonu') }}</span>
                                        @else
                                            {{ $fmt($r, $k) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="sticky bottom-0 z-20">
                        <tr>
                            @foreach($columns as $k => $c)
                                <td x-show="!hidden[@js($c['g'])]" @class(['px-3 py-2.5 border-t-2 border-r border-line-strong bg-surface-2 font-semibold whitespace-nowrap',
                                    'text-right font-mono tabular-nums' => $c['t'] === 'azn', 'text-danger' => $c['t'] === 'azn' && ($totals[$k] ?? 0) < -0.005,
                                    'sticky z-10' => in_array($k, ['no', 'seller_no']), 'left-0' => $k === 'no', 'left-[44px]' => $k === 'seller_no'])>
                                    @if($k === 'seller_no'){{ __('Cəmi') }}@elseif($c['t'] === 'azn' && ($totals[$k] ?? null) !== null){{ money($totals[$k]) }}@endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- how a column is calculated, with every row's numbers --}}
            <div x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="open = null">
                <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = null"></div>
                <template x-if="open">
                    <div class="relative w-full max-w-5xl max-h-[88vh] card !shadow-[var(--shadow-pop)] flex flex-col" x-trap.noscroll="open" x-transition.opacity>
                        <header class="flex items-start justify-between gap-4 px-6 py-4 border-b border-line">
                            <div class="min-w-0">
                                <div class="text-[11px] uppercase tracking-wider text-muted" x-text="meta[open].g"></div>
                                <h3 class="text-lg font-semibold" x-text="meta[open].l"></h3>
                                <div class="text-[11px] text-faint" x-show="meta[open].ref">{{ __('ATF cədvəlində sütun') }} <span class="font-mono" x-text="meta[open].ref"></span></div>
                            </div>
                            <button type="button" class="btn btn-ghost btn-icon" @click="open = null" aria-label="{{ __('Bağla') }}"><x-icon name="x" class="size-5"/></button>
                        </header>
                        <div class="px-6 py-5 overflow-y-auto space-y-5">
                            <div class="rounded-xl bg-surface-2 p-4" x-show="meta[open].f">
                                <div class="text-[11px] uppercase tracking-wider text-muted mb-1">{{ __('Hesablama qaydası') }}</div>
                                <div class="font-mono text-base text-ink" x-text="(meta[open].ref || '') + ' = ' + meta[open].f"></div>
                                <ul class="mt-3 space-y-1 text-sm" x-show="meta[open].in.length">
                                    <template x-for="i in meta[open].in" :key="i.k">
                                        <li class="flex gap-2"><span class="font-mono text-brand-ink w-10 shrink-0" x-text="i.ref || i.k"></span><span class="text-ink-2"><span x-text="i.l"></span><span class="text-muted" x-show="i.day" x-text="' — ' + i.day"></span></span></li>
                                    </template>
                                </ul>
                            </div>
                            <p class="text-sm text-ink-2" x-show="meta[open].note" x-text="meta[open].note"></p>
                            <p class="text-sm text-ink-2" x-show="!meta[open].f && !meta[open].note">{{ __('Bu sütun məlumatdan birbaşa götürülür.') }}</p>
                            {{-- 1C: the postings behind the figure, each a step between two points (no netting) --}}
                            <template x-if="meta[open].onec">
                                <div>
                                    <div class="text-[11px] uppercase tracking-wider text-muted mb-2">{{ $oneC ? __('Yazılışlar (dövr: 1 yanvar → ay sonu)') : __('Yazılışlar (Trade-in bütün müddəti)') }}</div>
                                    <div class="space-y-3">
                                        <template x-for="(c, n) in cells.filter(c => c.e && c.e.length)" :key="'e' + n">
                                            <div class="rounded-lg border border-line overflow-hidden">
                                                <div class="px-3 py-2 bg-surface-2 text-sm"><span class="font-mono font-medium" x-text="c.label"></span> <span class="text-[11px] text-muted" x-text="'· Trade ' + c.trade"></span></div>
                                                <table class="table-g text-[12px]">
                                                    <tbody>
                                                    <template x-for="(e, j) in c.e.filter(e => ['C1_A', 'C1_B', 'C1_C', 'C1_D', 'C1_E'].includes(meta[open].key) ? e.col === meta[open].key : true)" :key="j">
                                                        <tr>
                                                            <td class="whitespace-nowrap" x-text="e.item"></td>
                                                            <td class="font-mono text-muted whitespace-nowrap" x-text="e.when"></td>
                                                            <td class="font-mono text-muted" x-text="e.calc"></td>
                                                            <td class="num font-semibold whitespace-nowrap" :class="e.diff > 0 ? 'text-success' : 'text-danger'" x-text="(e.diff > 0 ? '+' : '−') + glaustFmt.fmt(Math.abs(e.diff), 2)"></td>
                                                            <td class="text-[11px] text-muted" x-text="e.line"></td>
                                                        </tr>
                                                    </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <div>
                                <div class="text-[11px] uppercase tracking-wider text-muted mb-2">{{ __('Hər faktura üzrə') }}</div>
                                <div class="overflow-x-auto rounded-lg border border-line">
                                    <table class="table-g text-sm">
                                        <thead><tr>
                                            <th>{{ __('Faktura') }}</th>
                                            <template x-for="i in meta[open].in" :key="i.k"><th class="!text-right" x-text="i.ref || i.k"></th></template>
                                            <th class="!text-right" x-text="meta[open].ref || {{ \Illuminate\Support\Js::from(__('Dəyər')) }}"></th>
                                        </tr></thead>
                                        <tbody>
                                            <template x-for="(c, n) in cells" :key="n">
                                                <tr>
                                                    <td class="whitespace-nowrap"><span class="font-mono" x-text="c.label"></span> <span class="block text-[11px] text-muted" x-text="'Trade ' + c.trade"></span></td>
                                                    <template x-for="i in meta[open].in" :key="i.k"><td class="num text-muted whitespace-nowrap"><span x-text="c.v[i.k]"></span><span class="block text-[11px] text-faint font-sans" x-show="c.d[i.k]" x-text="c.d[i.k]"></span></td></template>
                                                    <td class="num font-semibold whitespace-nowrap bg-brand-soft/30"><span x-text="c.v[open]"></span><span class="block text-[11px] font-normal text-faint font-sans" x-show="c.d[open]" x-text="c.d[open]"></span></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    @endif
</x-layouts.app>
