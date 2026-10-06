<x-layouts.app :title="__('Məzənnə fərqi')" wide>
    <x-page-header :title="__('Müsbət və mənfi məzənnə fərqi')" icon="scale"
                   :subtitle="__('Vergi Məcəlləsi 69, 13.2.12, 108.1 · CBAR məzənnəsi ilə · mənfəət bəyannaməsi 214 / 219.3')"/>

    @php
        $req = request();
        $initLines = $req->input('lines') ?: [['case' => 'verilmis_avans', 'currency' => 'EUR', 'amount' => '', 'date' => '', 'note' => '', 'nonres' => '']];
        $projectData = $projects->map(fn ($p) => ['id' => $p->id, 'label' => $p->code.' · '.$p->name, 'end' => $p->end_date?->toDateString()])->values();
        $rate = fn ($r) => number_format((float) $r, 4, ',', ' ');
        $labels = \App\Support\FxDifference::labels();
    @endphp

    <form method="GET" action="{{ route('fx-difference.index') }}" class="space-y-6" x-data="{
            lines: @js(array_values(array_map(fn ($l) => array_merge(['case' => 'verilmis_avans', 'currency' => 'EUR', 'amount' => '', 'date' => '', 'note' => '', 'nonres' => ''], (array) $l), $initLines))),
            projects: @js($projectData),
            project: @js((string) $req->input('project_id', '')),
            end: @js((string) $req->input('end_date', '')),
            forecast: @js((object) $req->input('forecast', [])),
            today: @js(today()->toDateString()),
            rates: {}, errors: {},
            labels: @js(collect(\App\Support\FxDifference::CASES)->mapWithKeys(fn ($c) => [$c => \App\Support\FxDifference::dateLabels($c)])),
            num(v) { return parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')) || 0; },
            fmt(v, d = 2) { return Number(v).toLocaleString('ru-RU', { minimumFractionDigits: d, maximumFractionDigits: d }); },
            add() { const l = this.lines[this.lines.length - 1] || {}; this.lines.push({ case: l.case || 'verilmis_avans', currency: l.currency || 'EUR', amount: '', date: '', note: '', nonres: '' }); },
            pickProject() { const p = this.projects.find(p => String(p.id) === String(this.project)); if (p && p.end) this.end = p.end; },
            key(c, d) { return c + '|' + d; },
            async rate(c, d) {
                if (!c || !d || d > this.today) return null;
                const k = this.key(c, d);
                if (!(k in this.rates)) {
                    this.rates[k] = null;
                    const r = await glaustApi('/ajax/rate?currency=' + c + '&date=' + encodeURIComponent(d));
                    if (r.ok) this.rates[k] = r.rate; else this.errors[k] = r.message;
                }
                return this.rates[k];
            },
            r(c, d) { return this.rates[this.key(c, d)] ?? null; },
            get future() { return this.end && this.end > this.today; },
            get usedCurrencies() { return [...new Set(this.lines.map(l => l.currency))]; },
            load() { this.lines.forEach(l => { this.rate(l.currency, l.date); this.rate(l.currency, this.end); }); },
        }" x-init="load(); $watch('lines', () => load()); $watch('end', () => load())">

        {{-- 1. operations --}}
        <section class="card">
            <header class="px-5 py-4 border-b border-line flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">1. {{ __('Əməliyyatlar') }}</h2>
                    <p class="text-xs text-muted">{{ __('Alış / ödəniş tarixi, məbləğ və valyuta — həmin günün CBAR məzənnəsi avtomatik yazılır') }}</p>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="size-4"/> {{ __('Sətir əlavə et') }}</button>
            </header>
            <div class="divide-y divide-line">
                <template x-for="(l, i) in lines" :key="i">
                    <div class="p-4 sm:p-5 grid grid-cols-2 lg:grid-cols-12 gap-3 items-end">
                        <label class="col-span-2 lg:col-span-4">
                            <span class="field-label"><span x-text="(i + 1) + '. '"></span>{{ __('Əməliyyatın növü') }}</span>
                            <select class="input" :name="'lines[' + i + '][case]'" x-model="l.case">
                                @foreach($labels as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select>
                        </label>
                        <label class="lg:col-span-2">
                            <span class="field-label" x-text="labels[l.case][0] + ' — {{ __('tarix') }}'"></span>
                            <input type="date" class="input font-mono" :name="'lines[' + i + '][date]'" x-model="l.date" :max="end && end < today ? end : today" required>
                        </label>
                        <label>
                            <span class="field-label">{{ __('Valyuta') }}</span>
                            <select class="input" :name="'lines[' + i + '][currency]'" x-model="l.currency">
                                @foreach($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                            </select>
                        </label>
                        <label class="lg:col-span-2">
                            <span class="field-label">{{ __('Məbləğ') }}</span>
                            <input type="text" inputmode="decimal" class="input font-mono text-right" :name="'lines[' + i + '][amount]'" x-model="l.amount" placeholder="10 000,00" required>
                        </label>
                        <div class="lg:col-span-2">
                            <span class="field-label">{{ __('CBAR məzənnəsi') }}</span>
                            <div class="input bg-surface-2 font-mono flex items-center" x-text="r(l.currency, l.date) ? fmt(r(l.currency, l.date), 4) : '—'"></div>
                            <p class="field-hint font-mono" x-show="r(l.currency, l.date) && num(l.amount)" x-text="'= ' + fmt(num(l.amount) * r(l.currency, l.date)) + ' ₼'"></p>
                            <p class="field-error" x-show="errors[key(l.currency, l.date)]" x-text="errors[key(l.currency, l.date)]"></p>
                        </div>
                        <div class="flex items-end justify-end">
                            <button type="button" class="btn btn-ghost btn-sm text-danger" @click="lines.splice(i, 1)" x-show="lines.length > 1" title="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                        </div>
                        <label class="col-span-2 lg:col-span-6">
                            <span class="field-label">{{ __('Qeyd (istəyə bağlı)') }}</span>
                            <input type="text" class="input" maxlength="120" :name="'lines[' + i + '][note]'" x-model="l.note" placeholder="{{ __('məs. Ellis — faktura 0152') }}">
                        </label>
                        <label class="col-span-2 lg:col-span-6 flex items-center gap-2 text-sm pb-2" x-show="['verilmis_avans', 'alis_borc'].includes(l.case)">
                            <input type="hidden" :name="'lines[' + i + '][nonres]'" value="0">
                            <input type="checkbox" class="checkbox" :name="'lines[' + i + '][nonres]'" value="1" :checked="l.nonres == '1'" @change="l.nonres = $event.target.checked ? '1' : ''">
                            {{ __('Qeyri-rezidentdən xidmət alışıdır (ƏDV və ÖMV bazası göstərilsin)') }}
                        </label>
                    </div>
                </template>
            </div>
        </section>

        {{-- 2. the project's end --}}
        <section class="card p-5">
            <h2 class="text-base font-semibold">2. {{ __('Layihənin bitmə tarixi') }}</h2>
            <p class="text-xs text-muted mb-4">{{ __('Mal/xidmətin qəbul (və ya ödəniş) günü — fərq bu tarixin CBAR məzənnəsi ilə hesablanır') }}</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <label>
                    <span class="field-label">{{ __('Layihə (istəyə bağlı)') }}</span>
                    <select name="project_id" class="input" x-model="project" @change="pickProject()">
                        <option value="">{{ __('— seçilməyib —') }}</option>
                        <template x-for="p in projects" :key="p.id"><option :value="p.id" x-text="p.label + (p.end ? ' · ' + p.end.split('-').reverse().join('.') : '')" :selected="String(p.id) === String(project)"></option></template>
                    </select>
                </label>
                <x-field :label="__('Bitmə tarixi')" name="end_date" required>
                    <input type="date" name="end_date" class="input font-mono" x-model="end" required>
                </x-field>
                <div class="text-sm self-end pb-2 text-muted" x-show="end && !future">
                    <template x-for="c in usedCurrencies" :key="c"><div class="font-mono"><span x-text="c"></span>: <span x-text="r(c, end) ? fmt(r(c, end), 4) : '…'"></span></div></template>
                </div>
            </div>
            <div class="mt-4 rounded-xl border border-saffron/40 bg-saffron/5 p-4" x-show="future" x-cloak>
                <p class="text-sm text-saffron flex items-center gap-2 mb-3"><x-icon name="clock" class="size-4"/> {{ __('Bu tarix hələ gəlməyib — CBAR məzənnəsi yoxdur. Proqnoz məzənnəni özünüz daxil edin; nəticə proqnoz kimi göstəriləcək.') }}</p>
                <div class="flex flex-wrap gap-4">
                    <template x-for="c in usedCurrencies" :key="c">
                        <label class="w-44"><span class="field-label" x-text="c + ' — {{ __('proqnoz məzənnə') }}'"></span>
                            <input type="text" inputmode="decimal" class="input font-mono" :name="'forecast[' + c + ']'" x-model="forecast[c]" placeholder="1,9000" :required="future"></label>
                    </template>
                </div>
            </div>
            <label class="mt-4 flex items-start gap-2 text-sm">
                <input type="hidden" name="revalue_advances" value="0">
                <input type="checkbox" name="revalue_advances" value="1" class="checkbox mt-0.5" @checked($req->input('revalue_advances', '1') == '1')>
                <span>{{ __('Avanslar da il sonu (31.12) məzənnəsi ilə yenidən qiymətləndirilsin') }}
                    <span class="block text-xs text-muted">{{ __('Layihə ildən-ilə keçirsə, avansın fərqi iki hissəyə bölünür: 31.12-yə qədər olan hissə həmin ilin, qalanı qəbul ilinin mənfəətində tanınır.') }}</span></span>
            </label>
            @if($errors->any())
                <div class="mt-4 text-sm text-danger space-y-1">@foreach($errors->all() as $e)<p class="flex items-center gap-2"><x-icon name="alert" class="size-4"/> {{ $e }}</p>@endforeach</div>
            @endif
            <div class="mt-5 flex gap-2">
                <button class="btn btn-primary"><x-icon name="scale" class="size-4"/> {{ __('Hesabla') }}</button>
                @if($result)<a href="{{ route('fx-difference.index') }}" class="btn btn-ghost">{{ __('Sıfırla') }}</a>@endif
            </div>
        </section>
    </form>

    {{-- 3. the result --}}
    @if($result)
        <section class="mt-8" id="result">
            <h2 class="text-base font-semibold mb-1">3. {{ __('Hesablama') }}@if($result['project']) · <span class="text-muted font-normal">{{ $result['project']->code }} {{ $result['project']->name }}</span>@endif</h2>
            <p class="text-xs text-muted mb-4">{{ __('Bitmə tarixi') }}: <span class="font-mono">{{ azdate($result['end']) }}</span>@if($result['future']) · <span class="text-saffron">{{ __('proqnoz məzənnə ilə') }}</span>@endif</p>

            @foreach($result['errors'] as $e)<p class="mb-2 text-sm text-danger flex items-center gap-2"><x-icon name="alert" class="size-4"/> {{ $e }}</p>@endforeach
            @foreach($result['warnings'] as $w)<p class="mb-2 text-sm text-saffron flex items-center gap-2"><x-icon name="info" class="size-4"/> {{ $w }}</p>@endforeach

            @php $net = round($result['positive'] - $result['negative'], 2); @endphp
            <div class="grid sm:grid-cols-3 gap-4 mb-6">
                <div class="card p-4"><div class="text-xs text-muted">{{ __('Müsbət məzənnə fərqi (gəlir)') }} · {{ __('sətir') }} 214 / 1212</div>
                    <div class="mt-1 font-mono text-2xl font-semibold text-success">{{ money($result['positive']) }}</div></div>
                <div class="card p-4"><div class="text-xs text-muted">{{ __('Mənfi məzənnə fərqi (xərc)') }} · {{ __('sətir') }} 219.3 / 1224.5</div>
                    <div class="mt-1 font-mono text-2xl font-semibold text-danger">{{ money($result['negative']) }}</div></div>
                <div class="card p-4"><div class="text-xs text-muted">{{ __('Xalis nəticə') }}</div>
                    <div @class(['mt-1 font-mono text-2xl font-semibold', 'text-success' => $net > 0, 'text-danger' => $net < 0])>{{ ($net > 0 ? '+' : ($net < 0 ? '−' : '')).money(abs($net)) }}</div></div>
            </div>

            @if(count($result['years']) > 1)
                <div class="card overflow-hidden mb-6">
                    <table class="table-g text-sm">
                        <thead><tr><th>{{ __('Mənfəət ili') }}</th><th class="!text-right">{{ __('Müsbət') }} (214)</th><th class="!text-right">{{ __('Mənfi') }} (219.3)</th></tr></thead>
                        <tbody>@foreach($result['years'] as $y => $v)
                            <tr><td class="font-mono">{{ $y }}</td><td class="num text-success">{{ isset($v['positive']) ? money($v['positive']) : '—' }}</td><td class="num text-danger">{{ isset($v['negative']) ? money($v['negative']) : '—' }}</td></tr>
                        @endforeach</tbody>
                    </table>
                </div>
            @endif

            <div class="space-y-4">
                @foreach($result['rows'] as $row)
                    @php $c = $row['calc']; $cur = $row['currency']; $amt = (float) $row['amount']; [$l1, $l2] = $row['labels']; @endphp
                    <article class="card overflow-hidden">
                        <header class="px-5 py-3 border-b border-line flex flex-wrap items-center justify-between gap-2">
                            <div class="font-semibold">{{ $row['n'] }}. {{ $labels[$row['case']] }}@if($row['note']) · <span class="font-normal text-muted">{{ $row['note'] }}</span>@endif</div>
                            <div class="font-mono">{{ money($amt, $cur) }}</div>
                        </header>
                        <div class="p-5 grid md:grid-cols-2 gap-5 text-sm">
                            <dl class="space-y-1.5">
                                <div class="flex justify-between gap-3"><dt class="text-muted">{{ $l1 }} · {{ azdate($row['date']) }}</dt><dd class="font-mono">{{ $rate($row['rate1']) }} → {{ money($c['book']) }}</dd></div>
                                @foreach($c['year_ends'] as $ye)
                                    <div class="flex justify-between gap-3"><dt class="text-saffron">{{ __('İl sonu') }} · {{ azdate($ye['date']) }}</dt><dd class="font-mono">{{ $rate($ye['rate']) }} → {{ money($ye['azn']) }}</dd></div>
                                @endforeach
                                <div class="flex justify-between gap-3"><dt class="text-muted">{{ $l2 }} · {{ azdate($result['end']) }}</dt><dd class="font-mono">{{ $rate($row['rate2']) }}@if($result['future'])*@endif → {{ money($c['settled']) }}</dd></div>
                                @if($c['recognized'] !== null)
                                    @php
                                        $income = in_array($row['case'], ['satis_borc', 'alinmis_avans']);
                                        $advance = in_array($row['case'], ['verilmis_avans', 'alinmis_avans']);
                                    @endphp
                                    <div class="border-t border-line pt-1.5"></div>
                                    @foreach($c['year_ends'] as $ye)
                                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ $advance ? ($income ? __('İl sonu gəlir kimi tanınır') : __('İl sonu xərc kimi tanınır')) : __('İl sonu borcun manat dəyəri') }} · {{ azdate($ye['date']) }}</dt><dd class="font-mono">{{ money($ye['azn']) }}</dd></div>
                                    @endforeach
                                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ $c['year_ends'] && $advance ? ($income ? __('Layihə sonunda gəlir kimi tanınır') : __('Layihə sonunda xərc kimi tanınır')) : ($income ? __('Gəlir kimi tanınır') : __('Xərc kimi tanınır')) }} · {{ azdate($advance ? $result['end'] : $row['date']) }}</dt><dd class="font-mono font-semibold">{{ money($c['recognized']) }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ in_array($row['case'], ['satis_borc', 'alinmis_avans']) ? __('Faktiki daxil olan manat') : __('Faktiki ödənilən manat') }}</dt><dd class="font-mono">{{ money($c['paid']) }}</dd></div>
                                @endif
                                @if($c['tax_base'] !== null)
                                    <div class="flex justify-between gap-3 border-t border-line pt-1.5"><dt class="text-muted">{{ __('ƏDV və ÖMV bazası') }} · {{ azdate($c['tax_base_date']) }}</dt><dd class="font-mono">{{ money($c['tax_base']) }}</dd></div>
                                    <div class="flex justify-between gap-3 text-xs text-muted"><dt>{{ __('ƏDV 18% (vergi agenti, VM 169) / ÖMV 10% (VM 125, sazişdən asılı)') }}</dt><dd class="font-mono">{{ money($c['tax_base'] * 0.18) }} / {{ money($c['tax_base'] * 0.10) }}</dd></div>
                                @endif
                            </dl>
                            <div>
                                <table class="table-g text-sm">
                                    <thead><tr><th>{{ __('Tanınma tarixi') }}</th><th>{{ __('Nəticə') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th></tr></thead>
                                    <tbody>
                                    @foreach($c['steps'] as $s)
                                        <tr>
                                            <td class="font-mono text-xs">{{ azdate($s['date']) }}<div class="text-faint font-sans">{{ $s['why'] }} · {{ $rate($s['rate_from']) }} → {{ $rate($s['rate_to']) }}</div></td>
                                            <td>@if($s['kind'] === 'positive')<span class="badge badge-green">{{ __('MÜSBƏT (gəlir)') }}</span> <span class="text-xs text-muted">214 / 1212</span>
                                                @elseif($s['kind'] === 'negative')<span class="badge badge-rose">{{ __('MƏNFİ (xərc)') }}</span> <span class="text-xs text-muted">219.3 / 1224.5</span>
                                                @else<span class="badge badge-slate">{{ __('YOXDUR') }}</span>@endif</td>
                                            <td @class(['num font-semibold', 'text-success' => $s['kind'] === 'positive', 'text-danger' => $s['kind'] === 'negative'])>{{ $s['kind'] === 'none' ? '—' : money($s['amount']) }}</td>
                                        </tr>
                                    @endforeach
                                    @if(! $c['steps'])<tr><td colspan="3" class="text-muted">{{ __('Əməliyyat və bitmə eyni gündədir — məzənnə fərqi yoxdur.') }}</td></tr>@endif
                                    </tbody>
                                </table>
                                <p class="mt-3 text-xs text-muted leading-relaxed">
                                    @switch($row['case'])
                                        @case('verilmis_avans')
                                            @if($result['revise_advances']) {{ __('Verilmiş avans il sonu 31.12 məzənnəsi ilə yenidən qiymətləndirilir: 31.12-yə qədərki fərq həmin ilin, qalanı (31.12 məzənnəsindən) qəbul ilinin mənfəətində tanınır. Yoxlama: xərc + mənfi fərq − müsbət fərq = ödənilən manat.') }}
                                            @else {{ __('Verilmiş avans 31.12-də yenidən qiymətləndirilmir. Fərq = qəbul günü dəyəri − avansın manat dəyəri, qəbul tarixində tanınır. Yoxlama: xərc + mənfi fərq − müsbət fərq = ödənilən manat.') }} @endif
                                            @break
                                        @case('alinmis_avans')
                                            @if($result['revise_advances']) {{ __('Alınmış avans il sonu 31.12 məzənnəsi ilə yenidən qiymətləndirilir: 31.12-yə qədərki fərq həmin ilin, qalanı təqdim ilinin mənfəətində tanınır.') }}
                                            @else {{ __('Alınmış avans 31.12-də yenidən qiymətləndirilmir. Fərq = avansın manat dəyəri − təqdim günü gəliri, təqdim tarixində tanınır.') }} @endif
                                            @break
                                        @case('alis_borc') {{ __('Kreditor borcu (öhdəlik): valyuta bahalaşıbsa — mənfi, ucuzlaşıbsa — müsbət. İl sonu açıq borc 31.12 məzənnəsi ilə yenidən qiymətləndirilir, sonrakı fərq 31.12 məzənnəsindən hesablanır (VM 69.2).') }} @break
                                        @case('satis_borc') {{ __('Debitor borcu (aktiv): valyuta bahalaşıbsa — müsbət, ucuzlaşıbsa — mənfi. İl sonu açıq borc 31.12 məzənnəsi ilə yenidən qiymətləndirilir (VM 69.2).') }} @break
                                        @default {{ __('İl sonu açıq qalığın 31.12 məzənnəsi ilə yenidən qiymətləndirilməsi; işarə aktiv/öhdəlik qaydasına görə (VM 69.2).') }}
                                    @endswitch
                                    {{ __('Müsbət fərq — satışdankənar gəlir (VM 13.2.12), mənfi fərq — xərc (VM 108.1).') }}
                                </p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.app>
