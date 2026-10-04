<x-layouts.app :title="__('Valyuta alış-satışı')" wide>
    @php
        $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->bank_name.' · '.$a->name, 'currency' => $a->currency, 'balance' => $a->currentBalance()])->values();
        $diffAzn = (float) $totals->diff_azn;
    @endphp
    <x-page-header :title="__('Valyuta alış-satışı')" icon="transfer" :subtitle="__('Bank hesablarımız arasında valyuta alışı və satışı — CBAR kursu və bankın kursu yan-yana saxlanılır')"/>
    @include('bank._tabs')

    <div class="grid sm:grid-cols-3 gap-4 mb-6 stagger">
        <div class="card p-4" style="--i:0"><div class="text-xs text-muted">{{ __('Əməliyyat') }}</div><div class="text-xl font-semibold font-mono">{{ $totals->n }}</div></div>
        <div class="card p-4" style="--i:1"><div class="text-xs text-muted">{{ __('Bank ilə CBAR fərqi (cəmi, AZN)') }}</div>
            <div @class(['text-xl font-semibold font-mono', 'text-danger' => $diffAzn > 0, 'text-success' => $diffAzn < 0])>{{ $diffAzn > 0 ? '−' : ($diffAzn < 0 ? '+' : '') }}{{ money(abs($diffAzn)) }}</div>
            <div class="text-[11px] text-muted">{{ $diffAzn > 0 ? 'CBAR-a görə itki' : ($diffAzn < 0 ? 'CBAR-a görə qazanc' : 'fərq yoxdur') }}</div></div>
        <div class="card p-4" style="--i:2"><div class="text-xs text-muted">{{ __('Hesablanma qaydası') }}</div><div class="text-[12px] text-ink-2 mt-1 leading-relaxed">{{ __('Alışda: bankın tələb etdiyi − CBAR ilə lazım olan · Satışda: CBAR ilə gələcək − bankın verdiyi') }}</div></div>
    </div>

    @can('bank.create')
        <form method="POST" action="{{ route('bank.exchanges.store') }}" class="card mb-6" x-data="{
                date: @js(old('exchange_date', today()->toDateString())),
                dir: @js(old('direction', 'buy')),
                cur: @js(old('currency', 'USD')),
                counter: @js(old('counter_currency', 'AZN')),
                amount: @js((string) old('amount', '')),
                bankRate: @js((string) old('bank_rate', '')),
                rate: { cur: null, counter: null }, loading: false, error: '',
                accounts: @js($accountsData),
                from: @js((string) old('from_account_id', '')), to: @js((string) old('to_account_id', '')),
                num(v) { return parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')) || 0; },
                async one(c) { if (c === 'AZN') return 1; const d = await glaustApi('/ajax/rate?currency=' + c + '&date=' + encodeURIComponent(this.date)); if (!d.ok) throw new Error(d.message); return d.rate; },
                async load() {
                    this.error = ''; this.rate = { cur: null, counter: null };
                    if (!this.date || this.cur === this.counter) return;
                    this.loading = true;
                    try { this.rate = { cur: await this.one(this.cur), counter: await this.one(this.counter) }; } catch (e) { this.error = e.message; }
                    this.loading = false;
                },
                cross() { return this.rate.cur && this.rate.counter ? this.rate.cur / this.rate.counter : null; },
                cbarAmount() { return this.cross() ? Math.round(this.num(this.amount) * this.cross() * 100) / 100 : null; },
                bankAmount() { return this.num(this.bankRate) ? Math.round(this.num(this.amount) * this.num(this.bankRate) * 100) / 100 : null; },
                diff() { if (this.cbarAmount() === null || this.bankAmount() === null) return null; return Math.round((this.dir === 'buy' ? this.bankAmount() - this.cbarAmount() : this.cbarAmount() - this.bankAmount()) * 100) / 100; },
                fromCur() { return this.dir === 'buy' ? this.counter : this.cur; },
                toCur() { return this.dir === 'buy' ? this.cur : this.counter; },
                opts(c) { return this.accounts.filter(a => a.currency === c); },
                acc(id) { return this.accounts.find(a => String(a.id) === String(id)); },
                sync() {
                    if (!this.opts(this.fromCur()).some(a => String(a.id) === this.from)) this.from = this.opts(this.fromCur())[0] ? String(this.opts(this.fromCur())[0].id) : '';
                    if (!this.opts(this.toCur()).some(a => String(a.id) === this.to)) this.to = this.opts(this.toCur())[0] ? String(this.opts(this.toCur())[0].id) : '';
                },
                fmt: (v) => glaustFmt.fmt(v, 2), rf: (v) => glaustFmt.fmtRate(v),
             }" x-init="load(); sync(); $watch('date', () => load()); $watch('cur', () => { load(); sync(); }); $watch('counter', () => { load(); sync(); }); $watch('dir', () => sync())">
            @csrf
            <header class="px-5 py-4 border-b border-line">
                <h2 class="text-base font-semibold">{{ __('Yeni əməliyyat') }}</h2>
                <p class="text-xs text-muted">{{ __('Əvvəlcə tarix — həmin günün CBAR kursu ilə hesablanır, sonra bankın kursunu daxil edirsiniz') }}</p>
            </header>
            <div class="p-5 grid xl:grid-cols-[minmax(0,1fr)_400px] gap-6">
                <div class="space-y-5 min-w-0">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-field :label="__('Tarix')" name="exchange_date" required><input type="date" name="exchange_date" x-model="date" max="{{ today()->toDateString() }}" class="input @error('exchange_date') is-invalid @enderror" required></x-field>
                        <div>
                            <span class="field-label">{{ __('Əməliyyat') }}</span>
                            <input type="hidden" name="direction" :value="dir">
                            <div class="segmented"><label :class="dir === 'buy' && 'is-on'"><input type="radio" value="buy" x-model="dir" class="sr-only"> {{ __('Valyuta alışı') }}</label><label :class="dir === 'sell' && 'is-on'"><input type="radio" value="sell" x-model="dir" class="sr-only"> {{ __('Valyuta satışı') }}</label></div>
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-[1fr_120px_140px] gap-4">
                        <x-field name="amount" required>
                            <x-slot:label><span x-text="dir === 'buy' ? 'Almaq istədiyimiz məbləğ' : 'Satmaq istədiyimiz məbləğ'"></span></x-slot:label>
                            <input name="amount" x-model="amount" inputmode="decimal" placeholder="10 000" class="input font-mono text-right @error('amount') is-invalid @enderror" required>
                        </x-field>
                        <x-field :label="__('Valyuta')" name="currency" required>
                            <select name="currency" x-model="cur" class="input">@foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                        </x-field>
                        <x-field name="counter_currency" required>
                            <x-slot:label><span x-text="dir === 'buy' ? 'Nə ilə ödəyirik' : 'Nə alırıq'"></span></x-slot:label>
                            <select name="counter_currency" x-model="counter" class="input @error('counter_currency') is-invalid @enderror">@foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                        </x-field>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-field name="from_account_id" required>
                            <x-slot:label>{{ __('Silinəcək hesab (') }}<span x-text="fromCur()"></span>)</x-slot:label>
                            <select name="from_account_id" x-model="from" class="input @error('from_account_id') is-invalid @enderror"><template x-for="a in opts(fromCur())" :key="a.id"><option :value="String(a.id)" x-text="a.label"></option></template></select>
                            <p class="text-[11px] mt-1" :class="acc(from) && acc(from).balance - (dir === 'buy' ? (bankAmount() || 0) : num(amount)) < 0 ? 'text-danger' : 'text-muted'" x-show="acc(from)">
                                {{ __('Qalıq:') }} <span class="font-mono" x-text="acc(from) && fmt(acc(from).balance)"></span> → <span class="font-mono" x-text="acc(from) && fmt(acc(from).balance - (dir === 'buy' ? (bankAmount() || 0) : num(amount)))"></span></p>
                            <p class="text-[11px] text-saffron" x-show="!opts(fromCur()).length"><span x-text="fromCur()"></span> {{ __('hesabı yoxdur') }}</p>
                        </x-field>
                        <x-field name="to_account_id" required>
                            <x-slot:label>{{ __('Mədaxil hesabı (') }}<span x-text="toCur()"></span>)</x-slot:label>
                            <select name="to_account_id" x-model="to" class="input @error('to_account_id') is-invalid @enderror"><template x-for="a in opts(toCur())" :key="a.id"><option :value="String(a.id)" x-text="a.label"></option></template></select>
                            <p class="text-[11px] text-muted mt-1" x-show="acc(to)">{{ __('Qalıq:') }} <span class="font-mono" x-text="acc(to) && fmt(acc(to).balance)"></span> → <span class="font-mono" x-text="acc(to) && fmt(acc(to).balance + (dir === 'buy' ? num(amount) : (bankAmount() || 0)))"></span></p>
                            <p class="text-[11px] text-saffron" x-show="!opts(toCur()).length"><span x-text="toCur()"></span> {{ __('hesabı yoxdur') }}</p>
                        </x-field>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-field :label="__('İstinad / bank sənədi №')" name="reference"><input name="reference" value="{{ old('reference') }}" class="input font-mono"></x-field>
                        <x-field :label="__('Qeyd')" name="notes"><input name="notes" value="{{ old('notes') }}" class="input"></x-field>
                    </div>
                </div>

                {{-- CBAR vs bank --}}
                <div class="rounded-xl border border-line overflow-hidden self-start">
                    <div class="px-4 py-3 bg-surface-2/60 border-b border-line">
                        <div class="text-xs text-muted">{{ __('CBAR kursu ·') }} <span x-text="date ? date.split('-').reverse().join('.') : '—'"></span></div>
                        <div class="font-mono font-semibold mt-0.5"><span x-show="loading" class="text-faint">{{ __('yüklənir…') }}</span>
                            <span x-show="!loading && cross()">1 <span x-text="cur"></span> = <span x-text="rf(cross())"></span> <span x-text="counter"></span></span></div>
                        <div class="text-[11px] text-muted" x-show="cross() && cur !== 'AZN' && counter !== 'AZN'"><span x-text="cur"></span> <span x-text="rf(rate.cur)"></span> ₼ · <span x-text="counter"></span> <span x-text="rf(rate.counter)"></span> ₼</div>
                        <div class="text-[11px] text-danger" x-show="error" x-text="error"></div>
                    </div>
                    <dl class="px-4 py-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted" x-text="dir === 'buy' ? 'CBAR ilə lazım olan' : 'CBAR ilə gələcək'"></dt><dd class="font-mono" x-text="cbarAmount() !== null ? fmt(cbarAmount()) + ' ' + counter : '—'"></dd></div>
                    </dl>
                    <div class="px-4 pb-3">
                        <label class="field-label" for="bank-rate">{{ __('Bankın kursu: 1') }} <span x-text="cur"></span> = ? <span x-text="counter"></span> <span class="text-danger">*</span></label>
                        <input id="bank-rate" name="bank_rate" x-model="bankRate" inputmode="decimal" :placeholder="cross() ? rf(cross()) : ''" class="input font-mono text-right @error('bank_rate') is-invalid @enderror" required>
                        @error('bank_rate')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <dl class="px-4 py-3 border-t border-line space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted" x-text="dir === 'buy' ? 'Bank ilə ödəniləcək' : 'Bankdan gələcək'"></dt><dd class="font-mono font-semibold" x-text="bankAmount() !== null ? fmt(bankAmount()) + ' ' + counter : '—'"></dd></div>
                        <div class="flex justify-between gap-3" x-show="diff() !== null">
                            <dt class="text-muted">{{ __('CBAR ilə fərq') }}</dt>
                            <dd class="font-mono font-semibold" :class="diff() > {{ __('0 ? \'text-danger\' : (diff()') }} < 0 ? 'text-success' : '')"
                                x-text="(diff() > {{ __('0 ? \'−\' : (diff()') }} < 0 ? '+' : '')) + fmt(Math.abs(diff())) + ' ' + counter + (diff() > {{ __('0 ? \' itki\' : (diff()') }} < 0 ? ' qazanc' : ''))"></dd>
                        </div>
                        <div class="flex justify-between gap-3 text-xs" x-show="diff() !== null && counter !== 'AZN'"><dt class="text-muted">{{ __('AZN ilə') }}</dt><dd class="font-mono" x-text="rate.counter ? fmt(Math.abs(diff() * rate.counter)) + ' ₼' : ''"></dd></div>
                    </dl>
                    <div class="px-4 pb-4">
                        <button class="btn btn-primary w-full" :disabled="!bankAmount() || !from || !to || cur === counter"><x-icon name="check" class="size-4"/> {{ __('Əməliyyatı icra et') }}</button>
                    </div>
                </div>
            </div>
        </form>
    @endcan

    <section class="card overflow-hidden">
        <header class="px-5 py-4 border-b border-line"><h2 class="text-base font-semibold">{{ __('Əməliyyatlar') }}</h2><p class="text-xs text-muted">{{ __('Hər əməliyyat üzrə CBAR və bank kursları, məbləğlər və fərq saxlanılır') }}</p></header>
        @if($list->isEmpty())
            <x-empty icon="transfer" :title="__('Hələ əməliyyat yoxdur')" :text="__('Yuxarıdakı formadan valyuta alışı və ya satışı edin.')"/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-[13px]">
                    <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Əməliyyat') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th class="!text-right">{{ __('CBAR kursu') }}</th><th class="!text-right">{{ __('Bank kursu') }}</th><th class="!text-right">{{ __('CBAR ilə') }}</th><th class="!text-right">{{ __('Bank ilə') }}</th><th class="!text-right">{{ __('Fərq') }}</th><th>{{ __('Hesablar') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                    @foreach($list as $x)
                        <tr>
                            <td data-label="Tarix" class="font-mono text-xs">{{ azdate($x->exchange_date) }}@if($x->reference)<div class="text-faint">{{ $x->reference }}</div>@endif</td>
                            <td data-label="Əməliyyat"><span class="badge {{ $x->direction === 'buy' ? 'badge-teal' : 'badge-amber' }}">{{ \App\Models\CurrencyExchange::DIRECTIONS[$x->direction] }}</span></td>
                            <td data-label="Məbləğ" class="num font-medium">{{ money($x->amount, $x->currency) }}</td>
                            <td data-label="CBAR kursu" class="num text-xs">{{ rate_fmt($x->cbar_cross) }}<div class="text-faint">{{ $x->currency }}/{{ $x->counter_currency }}</div></td>
                            <td data-label="Bank kursu" class="num text-xs font-medium">{{ rate_fmt($x->bank_rate) }}</td>
                            <td data-label="CBAR ilə" class="num">{{ money($x->counter_amount_cbar, $x->counter_currency) }}</td>
                            <td data-label="Bank ilə" class="num font-medium">{{ money($x->counter_amount, $x->counter_currency) }}</td>
                            <td data-label="Fərq" @class(['num', 'text-danger' => $x->difference > 0, 'text-success' => $x->difference < 0])>{{ $x->difference > 0 ? '−' : ($x->difference < 0 ? '+' : '') }}{{ money(abs($x->difference), $x->counter_currency) }}
                                @if($x->counter_currency !== 'AZN')<div class="text-[11px] text-faint">{{ money(abs($x->difference_azn)) }}</div>@endif</td>
                            <td data-label="Hesablar" class="text-xs">{{ $x->fromAccount?->name }} → {{ $x->toAccount?->name }}</td>
                            <td class="text-right">
                                @can('bank.delete')
                                    <form method="POST" action="{{ route('bank.exchanges.destroy', $x) }}" data-confirm="{{ __('Əməliyyat ləğv edilsin? Hər iki bank hərəkəti silinəcək.') }}" data-confirm-action="{{ __('Ləğv et') }}">
                                        @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="{{ __('Ləğv et') }}"><x-icon name="trash" class="size-4"/></button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $list->links() }}
        @endif
    </section>
</x-layouts.app>
