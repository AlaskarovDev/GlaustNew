<x-layouts.app :title="__('Bank hesabları auditi')" wide>
    @php
        $cur = $audit->currency;
        $acc = $audit->account;
        $b = $r['balances'];
        $c = $r['counts'];
        $matched = \App\Support\BankAudit\Reconciler::MATCHED;
        $okCount = array_sum(array_intersect_key($c, array_flip($matched)));
        $rows = collect($r['rows'])->filter(fn ($row) => match ($filter) {
            'issues' => ! in_array($row['status'], $matched, true),
            'ok' => in_array($row['status'], $matched, true),
            default => true,
        });
        $status = [
            'ok' => [__('Uyğun'), 'badge-green'],
            'split' => [__('Uyğun — sistemdə bölünüb'), 'badge-green'],
            'joined' => [__('Uyğun — bankda bir neçə sətir'), 'badge-green'],
            'regroup' => [__('Uyğun — bölgü fərqlidir'), 'badge-blue'],
            'date' => [__('Tarix fərqlidir'), 'badge-amber'],
            'amount' => [__('Məbləğ fərqlidir'), 'badge-amber'],
            'missing_sys' => [__('Sistemdə yoxdur'), 'badge-rose'],
            'missing_bank' => [__('Bankda yoxdur'), 'badge-rose'],
        ];
        $signed = fn (float $v) => ($v > 0 ? '+' : '−').money(abs($v), $cur);
        $base = ['audit' => $audit];
    @endphp
    <x-page-header :title="__('Bank hesabları auditi')" icon="list-checks" :subtitle="$acc?->bank_name.' · '.$acc?->name.' ('.$cur.')'.($audit->iban ? ' · '.$audit->iban : '').' · '.azdate($audit->period_from).' — '.azdate($audit->period_to)">
        <x-slot:actions>
            <a href="{{ route('bank.audits.index') }}" class="btn btn-secondary"><x-icon name="upload" class="size-4"/> {{ __('Yeni çıxarış yüklə') }}</a>
            @can('bank.import')
                <form method="POST" action="{{ route('bank.audits.destroy', $audit) }}" data-confirm="{{ __('Çıxarış silinsin? Sistemdəki əməliyyatlara toxunulmayacaq — sonra yenidən yükləyə bilərsiniz.') }}" data-confirm-action="{{ __('Sil') }}">
                    @csrf @method('DELETE')<button class="btn btn-secondary text-danger"><x-icon name="trash" class="size-4"/> {{ __('Auditi sil') }}</button>
                </form>
            @endcan
        </x-slot:actions>
    </x-page-header>
    @include('bank._tabs')

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
        @foreach([['opening', __('Dövrün əvvəlinə qalıq'), 'wallet'], ['closing', __('Dövrün sonuna qalıq'), 'layers']] as $i => [$k, $label, $ico])
            @php $same = abs($b[$k]['bank'] - $b[$k]['system']) < 0.01; @endphp
            <div class="card p-5" style="--i:{{ $i }}">
                <div class="flex items-center gap-2 text-xs text-muted"><x-icon :name="$ico" class="size-4"/> {{ $label }} <span class="font-mono">· {{ azdate($b[$k]['date']) }}</span></div>
                <div class="mt-1 text-xl font-semibold font-mono tabular-nums">{{ money($b[$k]['bank'], $cur) }}</div>
                <div @class(['text-[12px] font-mono mt-0.5', 'text-success' => $same, 'text-danger' => ! $same])>{{ __('sistemdə') }}: {{ money($b[$k]['system'], $cur) }}
                    @unless($same) · {{ __('fərq') }} {{ $signed($b[$k]['system'] - $b[$k]['bank']) }}@endunless</div>
            </div>
        @endforeach
        <div class="card p-5" style="--i:2">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="check-circle" class="size-4 text-success"/> {{ __('Uyğun') }}</div>
            <div class="mt-1 text-xl font-semibold font-mono tabular-nums text-success">{{ $okCount }}</div>
            <div class="text-[11px] text-faint">{{ $audit->lines()->count() }} {{ __('bank sətri') }}</div>
        </div>
        <div class="card p-5" style="--i:3">
            <div class="flex items-center gap-2 text-xs text-muted"><x-icon name="alert" class="size-4 text-danger"/> {{ __('Problemlər') }}</div>
            <div @class(['mt-1 text-xl font-semibold font-mono tabular-nums', 'text-danger' => $issues, 'text-success' => ! $issues])>{{ $issues }}</div>
            <div class="text-[11px] text-muted">
                @foreach(['missing_sys', 'missing_bank', 'date', 'amount'] as $k)@if($c[$k] ?? 0)<span class="whitespace-nowrap">{{ $status[$k][0] }}: {{ $c[$k] }}</span>@if(! $loop->last) · @endif @endif @endforeach
            </div>
        </div>
    </div>

    @if($b['split'])
        <div class="card border-saffron/40 bg-saffron-soft/50 p-4 mb-6 flex items-start gap-3 text-sm">
            <x-icon name="alert" class="size-5 text-saffron shrink-0"/>
            <div>{{ __('Qalıq ilk dəfə :v1 tarixində ayrılır: bankda :v2, sistemdə :v3. Problemləri tarix sırası ilə düzəltdikcə bu tarix irəli gedəcək.', ['v1' => azdate($b['split']['date']), 'v2' => money($b['split']['bank'], $cur), 'v3' => money($b['split']['system'], $cur)]) }}</div>
        </div>
    @elseif(! $issues)
        <div class="card border-success/30 bg-success-soft/50 p-4 mb-6 flex items-center gap-3 text-sm text-success"><x-icon name="check-circle" class="size-5 shrink-0"/> {{ __('Sistem bank çıxarışı ilə tam uyğundur.') }}</div>
    @endif

    <section class="card overflow-hidden">
        <header class="px-5 py-3 border-b border-line flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1" aria-label="{{ __('Filtr') }}">
                @foreach(['issues' => __('Problemlər').' ('.$issues.')', 'ok' => __('Uyğunlar').' ('.$okCount.')', 'all' => __('Hamısı').' ('.count($r['rows']).')'] as $k => $label)
                    <a href="{{ route('bank.audits.show', $base + ['show' => $k]) }}" @class(['btn btn-sm', 'btn-ghost' => $filter !== $k, 'bg-brand-soft text-brand-ink' => $filter === $k])>{{ $label }}</a>
                @endforeach
            </nav>
            <span class="text-[11px] text-muted">{{ __('Sol — bank çıxarışı, sağ — sistem. «Düzəlt» sistemi bank çıxarışındakı kimi edir.') }}</span>
        </header>
        @if($rows->isEmpty())
            <x-empty icon="check-circle" :title="$filter === 'issues' ? __('Problem yoxdur') : __('Sətir yoxdur')" :text="__('Bu filtrdə göstəriləcək sətir qalmayıb.')"/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-[13px]">
                    <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Bank çıxarışı') }}</th><th>{{ __('Sistem') }}</th><th>{{ __('Vəziyyət') }}</th><th class="w-48"></th></tr></thead>
                    <tbody>
                    @foreach($rows as $row)
                        @php
                            $ls = $row['lines'];
                            $ts = $row['txs'];
                            $fix = $row['fix'];
                            $src = $ts ? ($r['sources'][$fix['txs'][0] ?? $ts[0]['id']] ?? null) : null;
                        @endphp
                        <tr @class(['bg-danger-soft/30' => in_array($row['status'], ['missing_sys', 'missing_bank'], true), 'bg-saffron-soft/30' => in_array($row['status'], ['date', 'amount'], true)])>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs whitespace-nowrap">{{ azdate(($ls[0] ?? $ts[0])['date']) }}</td>
                            <td data-label="{{ __('Bank çıxarışı') }}" class="max-w-[420px]">
                                @forelse($ls as $l)
                                    <div @class(['mt-1.5' => ! $loop->first])>
                                        <span @class(['font-mono font-semibold whitespace-nowrap', 'text-success' => $l['amount'] > 0, 'text-danger' => $l['amount'] < 0])>{{ $signed($l['amount']) }}</span>
                                        <span class="text-[11px] text-faint font-mono">№{{ $l['m']->line_no }}</span>
                                        <div class="text-[11px] text-muted leading-snug line-clamp-2" title="{{ $l['m']->description }}">{{ $l['m']->description }}</div>
                                    </div>
                                @empty
                                    <span class="text-faint">—</span>
                                @endforelse
                            </td>
                            <td data-label="{{ __('Sistem') }}" class="max-w-[420px]">
                                @forelse($ts as $t)
                                    @php $s = $r['sources'][$t['id']] ?? null; @endphp
                                    <div @class(['mt-1.5' => ! $loop->first])>
                                        <a href="{{ route('bank.transactions.show', $t['id']) }}" @class(['font-mono font-semibold whitespace-nowrap hover:underline', 'text-success' => $t['amount'] > 0, 'text-danger' => $t['amount'] < 0])>{{ $signed($t['amount']) }}</a>
                                        @if($t['date'] !== ($ls[0]['date'] ?? $t['date']))<span class="text-[11px] font-mono text-saffron">{{ azdate($t['date']) }}</span>@endif
                                        <div class="text-[11px] text-muted leading-snug line-clamp-2">{{ $t['m']->purpose }}</div>
                                        @if($s)<div class="text-[11px]">@if($s['url'])<a href="{{ $s['url'] }}" class="text-brand-ink hover:underline">{{ $s['label'] }}</a>@else{{ $s['label'] }}@endif</div>@endif
                                    </div>
                                @empty
                                    <span class="text-faint">—</span>
                                @endforelse
                            </td>
                            <td data-label="{{ __('Vəziyyət') }}">
                                <span class="badge {{ $status[$row['status']][1] }} whitespace-nowrap">{{ $status[$row['status']][0] }}</span>
                                @if($row['status'] === 'amount')<div class="text-[11px] font-mono text-muted mt-1">{{ __('fərq') }} {{ $signed($ts[0]['amount'] - $ls[0]['amount']) }}</div>@endif
                            </td>
                            <td class="text-right">
                                @if($fix)
                                    @can('bank.update')
                                        @php
                                            [$btn, $ask] = match ($fix['action']) {
                                                'create' => [__('Sistemə əlavə et'), __('Bu sətir :v1 tarixi ilə :v2 olaraq hesaba yazılsın?', ['v1' => azdate($ls[0]['date']), 'v2' => $signed($ls[0]['amount'])])],
                                                'date' => [__('Tarixi :v1 et', ['v1' => azdate($ls[0]['date'])]), __('Əməliyyatın tarixi :v1 edilsin? CBAR kursları həmin günə yenidən hesablanacaq.', ['v1' => azdate($ls[0]['date'])]).($src ? ' '.__('Bağlı qeyd də dəyişəcək: :v1.', ['v1' => $src['label']]) : '')],
                                                'amount' => [__('Məbləği :v1 et', ['v1' => money(abs($ls[0]['amount']), $cur)]), __('Məbləğ bank çıxarışındakı kimi :v1 edilsin?', ['v1' => money(abs($ls[0]['amount']), $cur)])],
                                                'delete' => [__('Sistemdən sil'), __('Bu əməliyyat bank çıxarışında yoxdur. Silinsin?').($src ? ' '.__('Bağlı qeyd də silinəcək: :v1.', ['v1' => $src['label']]) : '')],
                                            };
                                        @endphp
                                        <form method="POST" action="{{ route('bank.audits.fix', $audit) }}" data-confirm="{{ $ask }}" data-confirm-action="{{ __('Düzəlt') }}">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $fix['action'] }}">
                                            @isset($fix['line'])<input type="hidden" name="line" value="{{ $fix['line'] }}">@endisset
                                            @isset($fix['tx'])<input type="hidden" name="tx" value="{{ $fix['tx'] }}">@endisset
                                            @foreach($fix['txs'] ?? [] as $tid)<input type="hidden" name="txs[]" value="{{ $tid }}">@endforeach
                                            <button @class(['btn btn-sm whitespace-nowrap', 'btn-danger' => $fix['action'] === 'delete', 'btn-primary' => $fix['action'] !== 'delete'])><x-icon :name="$fix['action'] === 'delete' ? 'trash' : 'check'" class="size-4"/> {{ $btn }}</button>
                                        </form>
                                    @endcan
                                @elseif($row['status'] === 'amount' && $src && $src['url'])
                                    <a href="{{ $src['url'] }}" class="btn btn-secondary btn-sm whitespace-nowrap">{{ __('Mənbədə düzəlt') }} <x-icon name="arrow-right" class="size-4"/></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
