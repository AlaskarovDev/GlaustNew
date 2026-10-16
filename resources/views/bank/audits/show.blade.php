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
            'manual' => [__('Əl ilə birləşdirilib'), 'badge-violet'],
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

    {{-- «Birləşdir»: pick bank lines and system movements whose totals are the same, tie them together --}}
    <section class="card overflow-hidden" x-data="{
            l: {}, t: {}, align: true, add: null, how: 'expense', cat: '',
            pick(side, id, amount, day, on) { const s = { ...this[side] }; if (on) s[id] = { a: amount, d: day }; else delete s[id]; this[side] = s; },
            n(side) { return Object.keys(this[side]).length; },
            sum(side) { return Math.round(Object.values(this[side]).reduce((x, v) => x + v.a, 0) * 100) / 100; },
            diff() { return Math.round((this.sum('l') - this.sum('t')) * 100) / 100; },
            days() { return [...new Set(Object.values(this.l).map(v => v.d))]; },
            offDay() { const d = this.days(); return d.length === 1 && Object.values(this.t).some(v => v.d !== d[0]); },
            ready() { return this.n('l') > 0 && this.n('t') > 0 && Math.abs(this.diff()) < 0.005; },
            clear() { this.l = {}; this.t = {}; document.querySelectorAll('[data-pick]').forEach(c => c.checked = false); },
         }">
        <header class="px-5 py-3 border-b border-line flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1" aria-label="{{ __('Filtr') }}">
                @foreach(['issues' => __('Problemlər').' ('.$issues.')', 'ok' => __('Uyğunlar').' ('.$okCount.')', 'all' => __('Hamısı').' ('.count($r['rows']).')'] as $k => $label)
                    <a href="{{ route('bank.audits.show', $base + ['show' => $k]) }}" @class(['btn btn-sm', 'btn-ghost' => $filter !== $k, 'bg-brand-soft text-brand-ink' => $filter === $k])>{{ $label }}</a>
                @endforeach
            </nav>
            <span class="text-[11px] text-muted">{{ __('Sol — bank çıxarışı, sağ — sistem. «Düzəlt» sistemi bank çıxarışındakı kimi edir.') }}
                @can('bank.update') {{ __('Cəmi eyni olan sətirləri işarələyib «Birləşdir» edə bilərsiniz.') }}@endcan</span>
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
                            $pickable = ! in_array($row['status'], $matched, true) && auth()->user()->can('bank.update');
                        @endphp
                        <tr @class(['bg-danger-soft/30' => in_array($row['status'], ['missing_sys', 'missing_bank'], true), 'bg-saffron-soft/30' => in_array($row['status'], ['date', 'amount'], true)])>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs whitespace-nowrap">{{ azdate(($ls[0] ?? $ts[0])['date']) }}</td>
                            <td data-label="{{ __('Bank çıxarışı') }}" class="max-w-[420px]">
                                @forelse($ls as $l)
                                    <div @class(['mt-1.5' => ! $loop->first])>
                                        @if($pickable)<input type="checkbox" class="checkbox align-middle mr-1" data-pick aria-label="{{ __('Birləşdirmək üçün seç') }}"
                                            @change="pick('l', {{ $l['id'] }}, {{ $l['amount'] }}, '{{ $l['date'] }}', $event.target.checked)">@endif
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
                                        @if($pickable)<input type="checkbox" class="checkbox align-middle mr-1" data-pick aria-label="{{ __('Birləşdirmək üçün seç') }}"
                                            @change="pick('t', {{ $t['id'] }}, {{ $t['amount'] }}, '{{ $t['date'] }}', $event.target.checked)">@endif
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
                                        @if($fix['action'] === 'create' && $ls[0]['amount'] < 0)
                                            {{-- money out: as what? a bank movement, an expense of a category, or another expense filled on its page --}}
                                            @php
                                                $add = ['line' => $fix['line'], 'date' => azdate($ls[0]['date']), 'amount' => $signed($ls[0]['amount']), 'text' => $ls[0]['m']->description,
                                                    'url' => route('expenses.create', ['date' => $ls[0]['date'], 'amount' => abs($ls[0]['amount']), 'bank_account_id' => $audit->bank_account_id,
                                                        'description' => \Illuminate\Support\Str::limit((string) $ls[0]['m']->description, 250, '…'), 'back' => request()->fullUrl()])];
                                            @endphp
                                            <button type="button" class="btn btn-primary btn-sm whitespace-nowrap" @click="add = {{ \Illuminate\Support\Js::from($add) }}; how = 'expense'"><x-icon name="check" class="size-4"/> {{ __('Sistemə əlavə et') }}</button>
                                        @else
                                        <form method="POST" action="{{ route('bank.audits.fix', $audit) }}" data-confirm="{{ $ask }}" data-confirm-action="{{ __('Düzəlt') }}">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $fix['action'] }}">
                                            @isset($fix['line'])<input type="hidden" name="line" value="{{ $fix['line'] }}">@endisset
                                            @isset($fix['tx'])<input type="hidden" name="tx" value="{{ $fix['tx'] }}">@endisset
                                            @foreach($fix['txs'] ?? [] as $tid)<input type="hidden" name="txs[]" value="{{ $tid }}">@endforeach
                                            <button @class(['btn btn-sm whitespace-nowrap', 'btn-danger' => $fix['action'] === 'delete', 'btn-primary' => $fix['action'] !== 'delete'])><x-icon :name="$fix['action'] === 'delete' ? 'trash' : 'check'" class="size-4"/> {{ $btn }}</button>
                                        </form>
                                        @endif
                                    @endcan
                                @elseif($row['status'] === 'manual')
                                    @can('bank.update')
                                        <form method="POST" action="{{ route('bank.audits.unlink', [$audit, $row['group']]) }}" data-confirm="{{ __('Birləşdirmə ayrılsın? Sistemdə heç nə dəyişməyəcək.') }}" data-confirm-action="{{ __('Ayır') }}">
                                            @csrf @method('DELETE')<button class="btn btn-secondary btn-sm whitespace-nowrap"><x-icon name="x" class="size-4"/> {{ __('Ayır') }}</button>
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

        @can('bank.update')
            {{-- «Sistemə əlavə et» for money out: how to book it --}}
            <div x-cloak x-show="add" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="add = null">
                <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="add = null"></div>
                <template x-if="add">
                    <form method="POST" action="{{ route('bank.audits.fix', $audit) }}" class="relative card w-full max-w-lg p-5 space-y-4">
                        @csrf
                        <input type="hidden" name="line" :value="add.line">
                        <input type="hidden" name="action" :value="how === 'expense' ? 'expense' : 'create'">
                        <div>
                            <h3 class="text-lg font-semibold">{{ __('Bu sətir sistemə nə kimi əlavə olunsun?') }}</h3>
                            <div class="text-sm mt-1"><span class="font-mono" x-text="add.date"></span> · <span class="font-mono font-semibold text-danger" x-text="add.amount"></span></div>
                            <div class="text-[12px] text-muted mt-1 line-clamp-3" x-text="add.text"></div>
                        </div>
                        <label class="flex items-start gap-3 rounded-lg border border-line p-3 cursor-pointer" :class="how === 'expense' && 'border-brand bg-brand-soft/40'">
                            <input type="radio" class="mt-1" value="expense" x-model="how">
                            <span class="flex-1"><span class="font-medium">{{ __('Xərc kimi') }}</span>
                                <span class="block text-[12px] text-muted">{{ __('Seçilən kateqoriya ilə ödənilmiş xərc yazılır və hesabdan silinir.') }}</span>
                                <select name="category_id" class="input mt-2" x-model="cat" x-show="how === 'expense'" :required="how === 'expense'">
                                    <option value="">{{ __('Kateqoriya seçin') }}</option>
                                    @foreach($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                                </select>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 rounded-lg border border-line p-3 cursor-pointer" :class="how === 'movement' && 'border-brand bg-brand-soft/40'">
                            <input type="radio" class="mt-1" value="movement" x-model="how">
                            <span><span class="font-medium">{{ __('Bank əməliyyatı kimi') }}</span>
                                <span class="block text-[12px] text-muted">{{ __('Yalnız hesabdan çıxış yazılır — bank çıxarışındakı təyinatla.') }}</span></span>
                        </label>
                        <a :href="add.url" class="flex items-start gap-3 rounded-lg border border-line p-3 hover:border-brand">
                            <x-icon name="receipt" class="size-4 mt-1 text-muted"/>
                            <span><span class="font-medium">{{ __('Başqa xərc — xərc səhifəsində doldur') }}</span>
                                <span class="block text-[12px] text-muted">{{ __('Tarix, məbləğ, hesab və təyinat hazır gəlir; kontragent, layihə və s. özünüz seçirsiniz. Yadda saxlayanda bura qayıdırsınız.') }}</span></span>
                        </a>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn btn-ghost" @click="add = null">{{ __('Ləğv et') }}</button>
                            <button class="btn btn-primary" :disabled="how === 'expense' && !cat"><x-icon name="check" class="size-4"/> {{ __('Əlavə et') }}</button>
                        </div>
                    </form>
                </template>
            </div>

            <div x-cloak x-show="n('l') + n('t') > 0" x-transition.opacity class="sticky bottom-0 z-30 border-t border-line bg-surface/95 backdrop-blur px-5 py-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <div><span class="text-muted">{{ __('Bank') }}:</span> <span class="font-mono font-semibold" x-text="n('l') + ' · ' + glaustFmt.fmt(sum('l'), 2)"></span></div>
                <div><span class="text-muted">{{ __('Sistem') }}:</span> <span class="font-mono font-semibold" x-text="n('t') + ' · ' + glaustFmt.fmt(sum('t'), 2)"></span></div>
                <div><span class="text-muted">{{ __('Fərq') }}:</span> <span class="font-mono font-semibold" :class="Math.abs(diff()) < 0.005 ? 'text-success' : 'text-danger'" x-text="glaustFmt.fmt(diff(), 2)"></span></div>
                <form method="POST" action="{{ route('bank.audits.link', $audit) }}" class="flex flex-wrap items-center gap-3 ml-auto">
                    @csrf
                    <template x-for="id in Object.keys(l)" :key="'l' + id"><input type="hidden" name="lines[]" :value="id"></template>
                    <template x-for="id in Object.keys(t)" :key="'t' + id"><input type="hidden" name="txs[]" :value="id"></template>
                    <label class="inline-flex items-center gap-2 text-[13px]" x-show="offDay()">
                        <input type="checkbox" class="checkbox" name="align" value="1" x-model="align"> {{ __('Sistemin tarixini bankdakı kimi et') }} <span class="font-mono" x-text="days()[0] ? days()[0].split('-').reverse().join('.') : ''"></span>
                    </label>
                    <button type="button" class="btn btn-ghost btn-sm" @click="clear()">{{ __('Təmizlə') }}</button>
                    <button class="btn btn-primary btn-sm" :disabled="!ready()" :title="ready() ? '' : @js(__('İki tərəfin cəmi eyni olmalıdır'))"><x-icon name="link" class="size-4"/> {{ __('Birləşdir') }}</button>
                </form>
            </div>
        @endcan
    </section>
</x-layouts.app>
