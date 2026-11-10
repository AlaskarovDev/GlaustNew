<x-layouts.app :title="$title" wide>
    <x-page-header :title="$title" :icon="$icon" :subtitle="$description">
        <x-slot:actions>
            <a href="{{ route('analytics.show', ['report' => 'projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ?: null, 'op' => $operation ?: null, 'fx' => $netFx ?: null, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
            <a href="{{ route('analytics.show', ['report' => 'projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => 1, 'op' => $operation ?: null, 'fx' => $netFx ?: null, 'format' => 'xlsx-forecast']) }}" class="btn btn-secondary"
               title="{{ __('Hər Trade-in proqnozu sətir-sətir: məbləğ, valyuta, proqnoz kursu və real kurs, fərq') }}"><x-icon name="sheet" class="size-4 text-saffron"/> {{ __('Proqnoz üzrə Excel') }}</a>
        </x-slot:actions>
    </x-page-header>

    @include('analytics._bar')

    @php
        $now = today();
        $quick = [
            __('Bu il') => [$now->format('Y').'-01', $now->format('Y-m')],
            __('Keçən il') => [($now->year - 1).'-01', ($now->year - 1).'-12'],
        ];
        $n = fn ($v) => abs((float) $v) < 0.005 ? '—' : ((float) $v < 0 ? '−' : '').num(abs((float) $v));
        $pct = fn ($v) => $v === null ? '—' : num($v, 1).'%';
        $t = $data['total'];
        // what each operation-day figure is made of: opened on a click
        $why = [];
        foreach (array_merge(array_merge([], ...array_column($data['projects'], 'trades')), $data['open']) as $tr) {
            if ($tr['opWhy'] ?? null) {
                $why['op|'.$tr['code']] = $tr['opWhy'];
            }
            $why['fx|'.$tr['code']] = $tr['fxWhy'];
            if ($tr['fcWhy'] ?? null) {
                $why['fc|'.$tr['code']] = $tr['fcWhy'];
            }
        }
        $tip = fn (string $key) => 'role="button" tabindex="0" @click="show(\''.$key.'\')" @keydown.enter="show(\''.$key.'\')"';
        $click = 'cursor-pointer hover:underline decoration-dotted underline-offset-4';
    @endphp

    <form method="GET" action="{{ route('analytics.show', 'projects') }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3">
        <label><span class="field-label">{{ __('Layihə') }}</span>
            <select name="project_id" class="input"><option value="">{{ __('Bütün layihələr') }}</option>
                @foreach($projectList as $pr)<option value="{{ $pr->id }}" @selected($projectId === $pr->id)>{{ $pr->code }} · {{ $pr->name }}</option>@endforeach
            </select></label>
        @if($forecast)<input type="hidden" name="forecast" value="1">@endif
        @if($operation)<input type="hidden" name="op" value="1">@endif
        @if($netFx)<input type="hidden" name="fx" value="1">@endif
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        <a href="{{ route('analytics.show', array_filter(['projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ? null : 1, 'op' => $operation ?: null, 'fx' => $netFx ?: null])) }}"
           @class(['btn', 'btn-secondary' => ! $forecast, 'bg-saffron-soft text-saffron' => $forecast]) title="{{ __('Fakturalarda yazılmış bank proqnozu (RUB çevirməsinin proqnoz EUR / RUB kursu) ilə mənfəət, faktla yanaşı') }}">
            <x-icon name="chart" class="size-4"/> {{ $forecast ? __('Proqnozu gizlət') : __('Proqnozla göstər') }}</a>
        <a href="{{ route('analytics.show', array_filter(['projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ?: null, 'op' => $operation ? null : 1, 'fx' => $netFx ?: null])) }}"
           @class(['btn', 'btn-secondary' => ! $operation, 'bg-brand-soft text-brand-ink' => $operation]) title="{{ __('Akt gəlməmişdən, satıcıya ödəniş günü: satış və alış həmin günün CBAR kursu ilə, komissiya, logistika — fakturadakı logistika proqnozu həmin günün kursu ilə') }}">
            <x-icon name="calendar" class="size-4"/> {{ $operation ? __('Əməliyyat tarixini gizlət') : __('Əməliyyat tarixinə göstər') }}</a>
        <a href="{{ route('analytics.show', array_filter(['projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ?: null, 'op' => $operation ?: null, 'fx' => $netFx ? null : 1])) }}"
           @class(['btn', 'btn-secondary' => ! $netFx, 'bg-danger-soft text-danger' => $netFx]) title="{{ __('Bankın rubl satışı və avro alışında CBAR-dan fərqi (itki) fakt, proqnoz və əməliyyat tarixi mənfəətlərinin hamısından çıxılır') }}">
            <x-icon name="transfer" class="size-4"/> {{ $netFx ? __('Kurs fərqi çıxılmadan göstər') : __('Kurs fərqi çıxılmaqla göstər') }}</a>
    </form>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Satış') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($t['revenue']) }}</div><div class="text-[11px] text-faint">{{ $label($from) }} — {{ $label($to) }}</div></div>
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Xərclər') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($t['revenue'] - $t['profit']) }}</div><div class="text-[11px] text-faint">{{ __('alış, logistika, komissiya') }}</div></div>
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Mənfəət') }}@if($netFx) <span class="text-danger">· {{ __('kurs fərqi çıxılmaqla') }}</span>@endif</div><div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-success' => $t['profit'] > 0, 'text-danger' => $t['profit'] < 0])>{{ money($t['profit']) }}</div><div class="text-[11px] text-faint">{{ __('Yekun hesabatın XALİS MƏNFƏƏTİ') }}</div></div>
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Marja') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ $pct($t['margin']) }}</div><div class="text-[11px] text-faint">{{ __('mənfəət ÷ satış') }}</div></div>
    </div>
    @if($forecast)
        <div class="grid sm:grid-cols-3 gap-4 mb-6">
            <div class="card p-5 border-saffron/40"><div class="text-xs text-muted">{{ __('Proqnoz mənfəət') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ $t['fc'] === null ? '—' : money($t['fc']) }}</div>
                <div class="text-[11px] text-faint">{{ __(':v1 Trade — bank proqnozu ilə', ['v1' => $t['fcCount']]) }}</div></div>
            <div class="card p-5"><div class="text-xs text-muted">{{ __('Fakt mənfəət') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ $t['fcFact'] === null ? '—' : money($t['fcFact']) }}</div>
                <div class="text-[11px] text-faint">{{ __('həmin Trade-lər üzrə') }}</div></div>
            <div class="card p-5"><div class="text-xs text-muted">{{ __('Fərq (fakt − proqnoz)') }}</div>
                <div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-success' => ($t['diff'] ?? 0) > 0, 'text-danger' => ($t['diff'] ?? 0) < 0])>{{ $t['diff'] === null ? '—' : ($t['diff'] > 0 ? '+' : ($t['diff'] < 0 ? '−' : '')).money(abs($t['diff'])) }}</div>
                <div class="text-[11px] text-faint">{{ __('+ fakt proqnozdan yaxşıdır') }}</div></div>
        </div>
    @endif
    @if($operation)
        <div class="grid sm:grid-cols-3 gap-4 mb-6">
            <div class="card p-5 border-brand/40"><div class="text-xs text-muted">{{ __('Əməliyyat tarixinə mənfəət') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ $t['op'] === null ? '—' : money($t['op']) }}</div>
                <div class="text-[11px] text-faint">{{ __(':v1 Trade — satıcıya ödəniş günü, logistika proqnozla', ['v1' => $t['opCount']]) }}</div></div>
            <div class="card p-5"><div class="text-xs text-muted">{{ __('Fakt mənfəət') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ $t['opFact'] === null ? '—' : money($t['opFact']) }}</div>
                <div class="text-[11px] text-faint">{{ __('həmin Trade-lər üzrə, aktdan sonra') }}</div></div>
            <div class="card p-5"><div class="text-xs text-muted">{{ __('Fərq (fakt − əməliyyat tarixinə)') }}</div>
                <div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-success' => ($t['opDiff'] ?? 0) > 0, 'text-danger' => ($t['opDiff'] ?? 0) < 0])>{{ $t['opDiff'] === null ? '—' : ($t['opDiff'] > 0 ? '+' : ($t['opDiff'] < 0 ? '−' : '')).money(abs($t['opDiff'])) }}</div>
                <div class="text-[11px] text-faint">{{ __('aktadək kurs və logistikanın təsiri') }}@if($data['openOp']) · {{ __('açıq Trade-lər: :v1', ['v1' => money($data['openOp']['sum'])]) }}@endif</div></div>
        </div>
    @endif

    <div x-data="{ open: null, why: @js($why), show(k) { if (this.why[k]) this.open = k; },
                   sign(v) { return v === null || v === undefined ? '—' : (v < 0 ? '−' : (v > 0 ? '+' : '')) + glaustFmt.fmt(Math.abs(v), 2); },
                   diff(f, v) { return f === null || f === undefined || v === null || v === undefined ? null : Math.round((f - v) * 100) / 100; } }">
    @forelse($data['projects'] as $p)
        <section class="card overflow-hidden mb-6">
            <header class="px-5 py-4 border-b border-line flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">@if($p['url'])<a href="{{ $p['url'] }}" class="hover:underline">{{ $p['name'] }}</a>@else{{ $p['name'] }}@endif</h2>
                    <p class="text-xs text-muted">{{ count($p['trades']) }} Trade · {{ __('marja') }} {{ $pct($p['sum']['margin']) }}</p>
                </div>
                <div class="text-right"><div class="text-[11px] text-muted">{{ __('Mənfəət') }}</div>
                    <div @class(['font-mono font-semibold text-lg', 'text-success' => $p['sum']['profit'] > 0, 'text-danger' => $p['sum']['profit'] < 0])>{{ money($p['sum']['profit']) }}</div></div>
            </header>
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-sm">
                    <thead><tr>
                        <th>Trade</th><th class="w-24">{{ __('Akt tarixi') }}</th>
                        <th class="!text-right">{{ __('Satış') }}</th><th class="!text-right">{{ __('Alış') }}</th><th class="!text-right">{{ __('Logistika') }}</th>
                        <th class="!text-right">{{ __('Komissiya') }}</th><th class="!text-right" title="{{ __('Bankın rubl/avro konvertasiyasında CBAR-dan fərqi: mənfəətdən çıxılmır, MZ-də bank xərci (221.13)') }}">{{ __('Bank kurs fərqi') }}</th><th class="!text-right">{{ __('Mənfəət') }}</th><th class="!text-right">{{ __('Marja') }}</th>
                        @if($forecast)<th class="!text-right">{{ __('Proqnoz mənfəət') }}</th><th class="!text-right">{{ __('Fərq') }}</th>@endif
                        @if($operation)<th class="!text-right">{{ __('Əməliyyat tarixinə') }}</th><th class="!text-right">{{ __('Fərq') }}</th>@endif
                        <th class="!text-right">{{ __('Alıcıdan daxil olub') }}</th><th class="!text-right">{{ __('Satıcıya ödənilib') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($p['trades'] as $tr)
                        <tr>
                            <td data-label="Trade"><a href="{{ $tr['url'] }}" class="font-mono text-xs text-brand-ink hover:underline">{{ $tr['code'] }}</a><div class="text-[11px] text-muted">{{ $tr['title'] }}</div></td>
                            <td data-label="{{ __('Akt tarixi') }}" class="font-mono text-xs">{{ azdate($tr['day']) }}</td>
                            <td data-label="{{ __('Satış') }}" class="num">{{ $n($tr['revenue']) }}</td>
                            <td data-label="{{ __('Alış') }}" class="num">{{ $n($tr['purchase']) }}</td>
                            <td data-label="{{ __('Logistika') }}" class="num">{{ $n($tr['logistics']) }}</td>
                            <td data-label="{{ __('Komissiya') }}" class="num">{{ $n($tr['fees']) }}</td>
                            <td data-label="{{ __('Kurs fərqi') }}" class="num {{ $click }}" {!! $tip('fx|'.$tr['code']) !!} title="{{ __('Necə yarandı — toxunun') }}">{{ $n($tr['fx']) }}</td>
                            <td data-label="{{ __('Mənfəət') }}" @class(['num font-semibold', 'text-success' => $tr['profit'] > 0, 'text-danger' => $tr['profit'] < 0])>{{ $n($tr['profit']) }}</td>
                            <td data-label="{{ __('Marja') }}" class="num">{{ $pct($tr['margin']) }}</td>
                            @if($forecast)
                                <td data-label="{{ __('Proqnoz mənfəət') }}" class="num text-saffron {{ $click }}" {!! $tip('fc|'.$tr['code']) !!} title="{{ __('Necə hesablandı — toxunun') }}">{{ $tr['fc'] === null ? __('proqnoz yoxdur') : $n($tr['fc']) }}</td>
                                <td data-label="{{ __('Fərq') }}" @class(['num font-semibold', 'text-success' => ($tr['diff'] ?? 0) > 0, 'text-danger' => ($tr['diff'] ?? 0) < 0])>{{ $tr['diff'] === null ? '—' : ($tr['diff'] > 0 ? '+' : '').$n($tr['diff']) }}</td>
                            @endif
                            @if($operation)
                                <td data-label="{{ __('Əməliyyat tarixinə') }}" class="num text-brand-ink {{ $click }}" {!! $tip('op|'.$tr['code']) !!} title="{{ __('Necə hesablandı — toxunun') }}">{{ $tr['op'] === null ? '—' : $n($tr['op']) }}@if($tr['opDay'])<div class="text-[11px] text-faint">{{ azdate($tr['opDay']) }}</div>@endif</td>
                                <td data-label="{{ __('Fərq') }}" @class(['num font-semibold', 'text-success' => ($tr['opDiff'] ?? 0) > 0, 'text-danger' => ($tr['opDiff'] ?? 0) < 0])>{{ $tr['opDiff'] === null ? '—' : ($tr['opDiff'] > 0 ? '+' : '').$n($tr['opDiff']) }}</td>
                            @endif
                            <td data-label="{{ __('Alıcıdan daxil olub') }}" class="num text-xs">{{ money($tr['received'], $tr['saleCur'] ?? 'AZN') }}<div class="text-faint">/ {{ money($tr['sold'], $tr['saleCur'] ?? 'AZN') }}</div></td>
                            <td data-label="{{ __('Satıcıya ödənilib') }}" class="num text-xs">{{ money($tr['paid'], $tr['cur'] ?? 'AZN') }}<div class="text-faint">/ {{ money($tr['bought'], $tr['cur'] ?? 'AZN') }}</div></td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold bg-surface-2">
                        <td colspan="2">{{ __('Cəmi') }}</td>
                        <td class="num">{{ $n($p['sum']['revenue']) }}</td><td class="num">{{ $n($p['sum']['purchase']) }}</td><td class="num">{{ $n($p['sum']['logistics']) }}</td>
                        <td class="num">{{ $n($p['sum']['fees']) }}</td><td class="num">{{ $n($p['sum']['fx']) }}</td><td class="num">{{ $n($p['sum']['profit']) }}</td><td class="num">{{ $pct($p['sum']['margin']) }}</td>
                        @if($forecast)
                            <td class="num text-saffron">{{ $p['sum']['fc'] === null ? '—' : $n($p['sum']['fc']) }}</td>
                            <td @class(['num', 'text-success' => ($p['sum']['diff'] ?? 0) > 0, 'text-danger' => ($p['sum']['diff'] ?? 0) < 0])>{{ $p['sum']['diff'] === null ? '—' : ($p['sum']['diff'] > 0 ? '+' : '').$n($p['sum']['diff']) }}</td>
                        @endif
                        @if($operation)
                            <td class="num text-brand-ink">{{ $p['sum']['op'] === null ? '—' : $n($p['sum']['op']) }}</td>
                            <td @class(['num', 'text-success' => ($p['sum']['opDiff'] ?? 0) > 0, 'text-danger' => ($p['sum']['opDiff'] ?? 0) < 0])>{{ $p['sum']['opDiff'] === null ? '—' : ($p['sum']['opDiff'] > 0 ? '+' : '').$n($p['sum']['opDiff']) }}</td>
                        @endif
                        <td colspan="2"></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <section class="card p-8 text-center text-sm text-muted mb-6">{{ __('Bu dövrdə aktı olan Trade yoxdur.') }}</section>
    @endforelse

    @if($data['open'])
        <section class="card overflow-hidden mb-6">
            <header class="px-5 py-4 border-b border-line">
                <h2 class="text-base font-semibold">{{ __('Açıq Trade-lər') }}</h2>
                <p class="text-xs text-muted">{{ __('Aktı hələ yoxdur: nəticə akt gələndə hesablanacaq') }}</p>
            </header>
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-sm">
                    <thead><tr><th>Trade</th><th>{{ __('Layihə') }}</th>@if($operation)<th class="!text-right">{{ __('Əməliyyat tarixinə mənfəət') }}</th>@endif<th class="!text-right">{{ __('Alıcıdan daxil olub') }}</th><th class="!text-right">{{ __('Satıcıya ödənilib') }}</th></tr></thead>
                    <tbody>
                    @foreach($data['open'] as $tr)
                        <tr>
                            <td data-label="Trade"><a href="{{ $tr['url'] }}" class="font-mono text-xs text-brand-ink hover:underline">{{ $tr['code'] }}</a><div class="text-[11px] text-muted">{{ $tr['title'] }}</div></td>
                            <td data-label="{{ __('Layihə') }}">{{ $tr['project'] }}</td>
                            @if($operation)<td data-label="{{ __('Əməliyyat tarixinə mənfəət') }}" class="num text-brand-ink {{ $click }}" {!! $tip('op|'.$tr['code']) !!} title="{{ __('Necə hesablandı — toxunun') }}">{{ $tr['op'] === null ? '—' : $n($tr['op']) }}@if($tr['opDay'])<div class="text-[11px] text-faint">{{ azdate($tr['opDay']) }}</div>@endif</td>@endif
                            <td data-label="{{ __('Alıcıdan daxil olub') }}" class="num text-xs">{{ money($tr['received'], $tr['saleCur'] ?? 'AZN') }}<div class="text-faint">/ {{ money($tr['sold'], $tr['saleCur'] ?? 'AZN') }}</div></td>
                            <td data-label="{{ __('Satıcıya ödənilib') }}" class="num text-xs">{{ money($tr['paid'], $tr['cur'] ?? 'AZN') }}<div class="text-faint">/ {{ money($tr['bought'], $tr['cur'] ?? 'AZN') }}</div></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
        {{-- the figure's make-up --}}
        <div x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="open = null">
            <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = null"></div>
            <template x-if="open">
                <div class="relative card w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden">
                    <header class="flex items-start justify-between gap-4 px-5 py-4 border-b border-line">
                        <div class="min-w-0">
                            <div class="text-[11px] uppercase tracking-wider text-muted" x-text="why[open].s"></div>
                            <h3 class="text-lg font-semibold" x-text="why[open].h"></h3>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" @click="open = null" aria-label="{{ __('Bağla') }}"><x-icon name="x" class="size-5"/></button>
                    </header>
                    <template x-if="why[open].kind === 'fx'">
                        <div class="overflow-auto flex-1">
                            <div class="px-5 py-4 text-[13px] text-ink-2 leading-relaxed bg-surface-2 border-b border-line space-y-1.5">
                                <p>{{ __('Mənfəətdə satış və alış CBAR kursu ilə hesablanır. Bank isə valyutanı öz kursu ilə dəyişir — aradakı fərq «bank kurs fərqi»dir:') }}</p>
                                <p>• <b>{{ __('Rubl satışı') }}</b> — {{ __('alıcının ödədiyi rubl bankda CBAR-dan ucuz satılır: itki = satılan rubl × (CBAR − bankın kursu).') }}</p>
                                <p>• <b>{{ __('Avro alışı') }}</b> — {{ __('satıcıya ödəmək üçün avro bankda CBAR-dan baha alınır: itki = alınan avro × (bankın kursu − CBAR).') }}</p>
                                <p class="text-muted">{{ __('«Mənfəət və zərər»də bu fərq Trade-ə yox, 221.13 «Bank xidməti üzrə xərclər»ə yazılır.') }}</p>
                            </div>
                            <table class="table-g text-[13px]">
                                <thead><tr><th>{{ __('Əməliyyat') }}</th><th>{{ __('Hesablama') }}</th><th class="!text-right">AZN</th></tr></thead>
                                <tbody>
                                    <template x-for="(r, i) in why[open].rows" :key="i">
                                        <tr><td class="font-medium" x-text="r.t"></td><td class="font-mono text-[12px] text-muted" x-text="r.c || ''"></td>
                                            <td class="num font-semibold whitespace-nowrap" :class="r.v < 0 ? 'text-danger' : ''" x-text="sign(r.v)"></td></tr>
                                    </template>
                                </tbody>
                            </table>
                            <div class="px-5 py-3 border-t border-line bg-surface-2 text-sm text-right font-semibold">{{ __('Bank kurs fərqi') }}: <span class="font-mono" x-text="sign(why[open].v)"></span></div>
                        </div>
                    </template>
                    <template x-if="why[open].kind !== 'fx'">
                    <div class="flex-1 flex flex-col min-h-0">
                    <div class="overflow-auto flex-1">
                        <table class="table-g text-[13px]">
                            <thead><tr><th>{{ __('Mənbə') }}</th><th class="!text-right" x-text="why[open].a"></th><th class="!text-right">{{ __('Fakt (aktdan sonra)') }}</th><th class="!text-right">{{ __('Fərq') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(r, i) in why[open].rows" :key="i">
                                    <tr>
                                        <td class="font-medium" x-text="r.t"></td>
                                        <td class="text-right"><div class="font-mono font-semibold whitespace-nowrap" :class="(r.v ?? 0) < 0 ? 'text-danger' : ''" x-text="sign(r.v)"></div><div class="font-mono text-[11px] text-muted" x-text="r.c || ''"></div></td>
                                        <td class="text-right"><div class="font-mono font-semibold whitespace-nowrap" :class="(r.f ?? 0) < 0 ? 'text-danger' : ''" x-text="sign(r.f)"></div><div class="font-mono text-[11px] text-muted" x-text="r.cf || ''"></div></td>
                                        <td class="num font-semibold whitespace-nowrap" :class="diff(r.f, r.v) > 0.004 ? 'text-success' : (diff(r.f, r.v) < -0.004 ? 'text-danger' : 'text-faint')" x-text="diff(r.f, r.v) === null ? '—' : (Math.abs(diff(r.f, r.v)) < 0.005 ? '0' : sign(diff(r.f, r.v)))"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <footer class="grid sm:grid-cols-3 gap-2 px-5 py-3 border-t border-line bg-surface-2 text-sm">
                        <div><span x-text="why[open].a"></span>: <span class="font-mono font-semibold" x-text="sign(why[open].v)"></span></div>
                        <div>{{ __('Fakt') }}: <span class="font-mono font-semibold" x-text="sign(why[open].f)"></span></div>
                        <div class="sm:text-right">{{ __('Fərq (fakt − ') }}<span x-text="why[open].a.toLowerCase()"></span>): <span class="font-mono font-semibold" :class="(diff(why[open].f, why[open].v) ?? 0) < 0 ? 'text-danger' : 'text-success'" x-text="diff(why[open].f, why[open].v) === null ? '—' : sign(diff(why[open].f, why[open].v))"></span></div>
                    </footer>
                    </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-layouts.app>
