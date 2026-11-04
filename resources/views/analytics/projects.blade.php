<x-layouts.app :title="$title" wide>
    <x-page-header :title="$title" :icon="$icon" :subtitle="$description">
        <x-slot:actions>
            <a href="{{ route('analytics.show', ['report' => 'projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ?: null, 'op' => $operation ?: null, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
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
        $now = today();
        $quick = [
            __('Bu il') => [$now->format('Y').'-01', $now->format('Y-m')],
            __('Keçən il') => [($now->year - 1).'-01', ($now->year - 1).'-12'],
        ];
        $n = fn ($v) => abs((float) $v) < 0.005 ? '—' : ((float) $v < 0 ? '−' : '').num(abs((float) $v));
        $pct = fn ($v) => $v === null ? '—' : num($v, 1).'%';
        $t = $data['total'];
    @endphp

    <form method="GET" action="{{ route('analytics.show', 'projects') }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3">
        <label><span class="field-label">{{ __('Ayından') }}</span><input type="month" name="from" value="{{ $from }}" class="input font-mono"></label>
        <label><span class="field-label">{{ __('Ayınadək') }}</span><input type="month" name="to" value="{{ $to }}" class="input font-mono"></label>
        <label><span class="field-label">{{ __('Layihə') }}</span>
            <select name="project_id" class="input"><option value="">{{ __('Bütün layihələr') }}</option>
                @foreach($projectList as $pr)<option value="{{ $pr->id }}" @selected($projectId === $pr->id)>{{ $pr->code }} · {{ $pr->name }}</option>@endforeach
            </select></label>
        @if($forecast)<input type="hidden" name="forecast" value="1">@endif
        @if($operation)<input type="hidden" name="op" value="1">@endif
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        <a href="{{ route('analytics.show', array_filter(['projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ? null : 1, 'op' => $operation ?: null])) }}"
           @class(['btn', 'btn-secondary' => ! $forecast, 'bg-saffron-soft text-saffron' => $forecast]) title="{{ __('Fakturalarda yazılmış bank proqnozu (RUB çevirməsinin proqnoz EUR / RUB kursu) ilə mənfəət, faktla yanaşı') }}">
            <x-icon name="chart" class="size-4"/> {{ $forecast ? __('Proqnozu gizlət') : __('Proqnozla göstər') }}</a>
        <a href="{{ route('analytics.show', array_filter(['projects', 'from' => $from, 'to' => $to, 'project_id' => $projectId, 'forecast' => $forecast ?: null, 'op' => $operation ? null : 1])) }}"
           @class(['btn', 'btn-secondary' => ! $operation, 'bg-brand-soft text-brand-ink' => $operation]) title="{{ __('Akt gəlməmişdən, satıcıya ödəniş günü: satış və alış həmin günün CBAR kursu ilə, komissiya və bankın kurs fərqi, logistika — fakturadakı logistika proqnozu həmin günün kursu ilə') }}">
            <x-icon name="calendar" class="size-4"/> {{ $operation ? __('Əməliyyat tarixini gizlət') : __('Əməliyyat tarixinə göstər') }}</a>
        <div class="flex flex-wrap gap-1 lg:ml-auto">
            @foreach($quick as $ql => [$qf, $qt])
                <a href="{{ route('analytics.show', ['projects', 'from' => $qf, 'to' => $qt, 'project_id' => $projectId, 'forecast' => $forecast ?: null, 'op' => $operation ?: null]) }}" @class(['btn btn-sm', 'btn-ghost' => ! ($qf === $from && $qt === $to), 'bg-brand-soft text-brand-ink' => $qf === $from && $qt === $to])>{{ $ql }}</a>
            @endforeach
        </div>
    </form>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Satış') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($t['revenue']) }}</div><div class="text-[11px] text-faint">{{ $label($from) }} — {{ $label($to) }}</div></div>
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Xərclər') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($t['revenue'] - $t['profit']) }}</div><div class="text-[11px] text-faint">{{ __('alış, logistika, komissiya, kurs fərqi') }}</div></div>
        <div class="card p-5"><div class="text-xs text-muted">{{ __('Mənfəət') }}</div><div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-success' => $t['profit'] > 0, 'text-danger' => $t['profit'] < 0])>{{ money($t['profit']) }}</div><div class="text-[11px] text-faint">{{ __('Trade-lər üzrə') }}</div></div>
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
                        <th class="!text-right">{{ __('Komissiya') }}</th><th class="!text-right">{{ __('Kurs fərqi') }}</th><th class="!text-right">{{ __('Mənfəət') }}</th><th class="!text-right">{{ __('Marja') }}</th>
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
                            <td data-label="{{ __('Kurs fərqi') }}" class="num">{{ $n($tr['fx']) }}</td>
                            <td data-label="{{ __('Mənfəət') }}" @class(['num font-semibold', 'text-success' => $tr['profit'] > 0, 'text-danger' => $tr['profit'] < 0])>{{ $n($tr['profit']) }}</td>
                            <td data-label="{{ __('Marja') }}" class="num">{{ $pct($tr['margin']) }}</td>
                            @if($forecast)
                                <td data-label="{{ __('Proqnoz mənfəət') }}" class="num text-saffron">{{ $tr['fc'] === null ? __('proqnoz yoxdur') : $n($tr['fc']) }}</td>
                                <td data-label="{{ __('Fərq') }}" @class(['num font-semibold', 'text-success' => ($tr['diff'] ?? 0) > 0, 'text-danger' => ($tr['diff'] ?? 0) < 0])>{{ $tr['diff'] === null ? '—' : ($tr['diff'] > 0 ? '+' : '').$n($tr['diff']) }}</td>
                            @endif
                            @if($operation)
                                <td data-label="{{ __('Əməliyyat tarixinə') }}" class="num text-brand-ink">{{ $tr['op'] === null ? '—' : $n($tr['op']) }}@if($tr['opDay'])<div class="text-[11px] text-faint">{{ azdate($tr['opDay']) }}</div>@endif</td>
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
                            @if($operation)<td data-label="{{ __('Əməliyyat tarixinə mənfəət') }}" class="num text-brand-ink">{{ $tr['op'] === null ? '—' : $n($tr['op']) }}@if($tr['opDay'])<div class="text-[11px] text-faint">{{ azdate($tr['opDay']) }}</div>@endif</td>@endif
                            <td data-label="{{ __('Alıcıdan daxil olub') }}" class="num text-xs">{{ money($tr['received'], $tr['saleCur'] ?? 'AZN') }}<div class="text-faint">/ {{ money($tr['sold'], $tr['saleCur'] ?? 'AZN') }}</div></td>
                            <td data-label="{{ __('Satıcıya ödənilib') }}" class="num text-xs">{{ money($tr['paid'], $tr['cur'] ?? 'AZN') }}<div class="text-faint">/ {{ money($tr['bought'], $tr['cur'] ?? 'AZN') }}</div></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>
