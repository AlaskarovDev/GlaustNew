{{-- Step 2 — Mədaxillər: the buyer pays us. Each payment is an incoming bank movement linked to the deal. --}}
@php
    $payments = $deal->payments;
    $expected = $deal->salesDocuments->where('kind', 'proforma')->groupBy('currency')->map(fn ($g) => $g->sum(fn ($d) => $d->grandTotal()));
    $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->name.' · '.$a->bank_name, 'currency' => $a->currency])->values();
    $defaultCurrency = old('currency', $expected->keys()->first() ?? $accounts->first()?->currency ?? 'AZN');
    $override = old('applied_rate') !== null && old('applied_rate') !== '';
@endphp
<div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
    @forelse($expected as $cur => $sum)
        @php $got = (float) ($received[$cur] ?? 0); $left = round($sum - $got, 2); @endphp
        <div class="card p-4" style="--i:0"><div class="text-xs text-muted">Gözlənilən (proforma)</div><div class="text-xl font-semibold font-mono">{{ money($sum, $cur) }}</div></div>
        <div class="card p-4" style="--i:1"><div class="text-xs text-muted">Daxil olub</div><div class="text-xl font-semibold font-mono text-success">{{ money($got, $cur) }}</div>
            <div class="mt-2 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full bg-success rounded-full" style="width: {{ $sum > 0 ? min(100, round($got / $sum * 100)) : 0 }}%"></div></div></div>
        <div class="card p-4" style="--i:2"><div class="text-xs text-muted">Qalıq</div><div @class(['text-xl font-semibold font-mono', 'text-saffron' => $left > 0, 'text-success' => $left <= 0])>{{ money(max($left, 0), $cur) }}</div>
            @if($left < 0)<div class="text-[11px] text-muted">Artıq ödəniş: {{ money(-$left, $cur) }}</div>@endif</div>
    @empty
        <div class="card p-4 sm:col-span-3" style="--i:0"><div class="text-xs text-muted">Gözlənilən</div><div class="text-sm text-muted mt-1">Proforma faktura hələ yoxdur — «Fakturalar» addımını tamamlayın.</div></div>
    @endforelse
    <div class="card p-4" style="--i:3"><div class="text-xs text-muted">AZN ekvivalenti (bank kursu)</div><div class="text-xl font-semibold font-mono">{{ money($payments->sum('amount_azn')) }}</div>
        @php $fx = round($payments->sum('amount_azn') - $payments->sum('cbar_amount_azn'), 2); @endphp
        <div class="text-[11px] {{ $fx < 0 ? 'text-danger' : 'text-muted' }}">CBAR ilə: {{ money($payments->sum('cbar_amount_azn')) }} · fərq {{ $fx > 0 ? '+' : '' }}{{ money($fx) }}</div></div>
</div>

@can('bank.create')
    <section class="card mb-6" x-data="{ open: {{ $errors->any() || $payments->isEmpty() ? 'true' : 'false' }} }">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" :class="open && 'border-b border-line'">
            <div>
                <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="arrow-down-left" class="size-5 text-success"/> Yeni mədaxil</h2>
                <p class="text-xs text-muted">Alıcının göndərdiyi ödəniş: məbləğ, tarix, valyuta, daxil olduğu bank hesabımız və bankın tətbiq etdiyi kurs</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="open = !open"><x-icon name="plus" class="size-4" x-show="!open"/><span x-text="open ? 'Bağla' : 'Gələn ödəniş əlavə et'"></span></button>
        </header>
        <form method="POST" action="{{ route('deals.payments.store', $deal) }}" x-show="open" x-collapse class="p-5" @if($payments->isNotEmpty() && ! $errors->any()) x-cloak @endif
              x-data="rateLookup({ currency: @js($defaultCurrency), date: @js(old('transaction_date', today()->toDateString())), amount: @js((string) old('amount', '')), override: @js($override), applied: @js((string) old('applied_rate', '')) })">
            @csrf
            <div class="space-y-5" x-data="{
                    accounts: @js($accountsData),
                    account: @js((string) old('bank_account_id', '')),
                    options() { return this.accounts.filter(a => a.currency === this.currency); },
                    syncAccount() { if (!this.options().some(a => String(a.id) === String(this.account))) this.account = this.options()[0] ? String(this.options()[0].id) : ''; },
                 }" x-init="syncAccount(); $watch('currency', () => syncAccount())">
            <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                    <span class="field-label">Kimdən</span>
                    <div class="input flex items-center gap-2 bg-surface-2 text-ink" aria-readonly="true"><x-icon name="building" class="size-4 text-muted shrink-0"/><span class="truncate">{{ $deal->counterparty?->name ?? 'Alıcı seçilməyib' }}</span></div>
                    <p class="text-[11px] text-muted mt-1">Məhsul alan tərəf — avtomatik</p>
                </div>
                <x-field label="Tarix" name="transaction_date" required>
                    <input type="date" name="transaction_date" x-model="date" max="{{ today()->toDateString() }}" class="input @error('transaction_date') is-invalid @enderror" required>
                </x-field>
                <x-field label="Valyuta" name="currency" required>
                    <select name="currency" x-model="currency" class="input">
                        @foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </x-field>
                <x-field label="Məbləğ" name="amount" required>
                    <input name="amount" x-model="amount" inputmode="decimal" placeholder="19 800 178,00" class="input font-mono text-right @error('amount') is-invalid @enderror" required>
                </x-field>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <x-field label="Daxil olduğu bank hesabımız" name="bank_account_id" required>
                    <select name="bank_account_id" x-model="account" class="input @error('bank_account_id') is-invalid @enderror" required>
                        <template x-for="a in options()" :key="a.id"><option :value="String(a.id)" x-text="a.label + ' (' + a.currency + ')'"></option></template>
                    </select>
                    <p class="text-[11px] text-saffron mt-1" x-show="!options().length" x-cloak>
                        <span x-text="currency"></span> valyutasında aktiv bank hesabı yoxdur. @can('bank.create')<a href="{{ route('bank.accounts.index') }}" class="underline">Hesab əlavə edin</a>@endcan
                    </p>
                </x-field>
                <div class="grid grid-cols-2 gap-4">
                    <x-field label="İstinad / ödəniş tapşırığı №" name="reference"><input name="reference" value="{{ old('reference') }}" class="input font-mono"></x-field>
                    <x-field label="Təyinat" name="purpose"><input name="purpose" value="{{ old('purpose') }}" placeholder="Tədarük {{ $deal->code }} üzrə ödəniş" class="input"></x-field>
                </div>
            </div>

            {{-- Rates: CBAR of the date (automatic) and the bank's own rate (typed in) --}}
            <div class="rounded-xl border border-line overflow-hidden" x-show="currency !== 'AZN'">
                <div class="grid md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-line">
                    <div class="p-4">
                        <div class="text-xs text-muted">CBAR kursu · <span x-text="date ? date.split('-').reverse().join('.') : '—'"></span></div>
                        <div class="font-mono text-lg font-semibold mt-1"><span x-show="loading" class="text-faint">…</span><span x-show="!loading">1 <span x-text="currency"></span> = <span x-text="rate(cbar)"></span> ₼</span></div>
                        <div class="text-[11px] text-danger" x-show="error" x-text="error"></div>
                    </div>
                    <div class="p-4">
                        <label class="text-xs text-muted" for="applied-rate">Bankın kursu (1 <span x-text="currency"></span> = ₼)</label>
                        <input id="applied-rate" name="applied_rate" x-model="applied" @input="override = true" inputmode="decimal" class="input mt-1 font-mono text-right @error('applied_rate') is-invalid @enderror">
                        <p class="text-[11px] mt-1" :class="override ? 'text-brand-ink' : 'text-muted'" x-text="override ? 'Əl ilə daxil edilib' : 'CBAR-dan götürülüb — bankın kursu fərqlidirsə dəyişin'"></p>
                    </div>
                    <div class="p-4 bg-surface-2/60">
                        <div class="text-xs text-muted">Ekvivalent (bankın kursu ilə)</div>
                        <div class="font-mono text-lg font-semibold mt-1" x-text="money(azn) + ' ₼'"></div>
                        <div class="text-[11px] text-muted">CBAR ilə: <span class="font-mono" x-text="money(cbarAzn) + ' ₼'"></span>
                            · fərq <span class="font-mono" :class="azn - cbarAzn < 0 ? 'text-danger' : 'text-success'" x-text="(azn - cbarAzn > 0 ? '+' : '') + money(azn - cbarAzn) + ' ₼'"></span></div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button class="btn btn-primary" :disabled="!options().length"><x-icon name="check" class="size-4"/> Mədaxili qeydə al</button>
            </div>
            </div>
        </form>
    </section>
@endcan

<section class="card overflow-hidden mb-6">
    <header class="px-5 py-4 border-b border-line">
        <h2 class="text-base font-semibold">Daxil olan ödənişlər <span class="text-muted font-mono font-normal text-sm">{{ $payments->count() }}</span></h2>
        <p class="text-xs text-muted">Hər mədaxil «Bank əməliyyatları» bölməsində də görünür</p>
    </header>
    @if($payments->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>Tarix</th><th>Kimdən</th><th class="!text-right">Məbləğ</th><th>Hesab</th><th class="!text-right">CBAR kursu</th><th class="!text-right">Bankın kursu</th><th class="!text-right">AZN (bank)</th><th class="!text-right">Fərq</th><th class="w-24"></th></tr></thead>
                <tbody>
                @foreach($payments as $p)
                    @php $diff = round((float) $p->amount_azn - (float) $p->cbar_amount_azn, 2); @endphp
                    <tr>
                        <td data-label="Tarix" class="font-mono text-xs">{{ azdate($p->transaction_date) }}</td>
                        <td data-label="Kimdən">{{ $deal->counterparty?->name }}@if($p->reference)<div class="text-[11px] text-muted font-mono">{{ $p->reference }}</div>@endif</td>
                        <td data-label="Məbləğ" class="num font-medium text-success">+{{ money($p->amount, $p->currency) }}</td>
                        <td data-label="Hesab" class="text-sm">{{ $p->account?->name }}<div class="text-[11px] text-muted">{{ $p->account?->bank_name }}</div></td>
                        <td data-label="CBAR kursu" class="num text-xs">{{ rate_fmt($p->cbar_rate) }}</td>
                        <td data-label="Bankın kursu" @class(['num text-xs', 'text-brand-ink font-medium' => (float) $p->applied_rate !== (float) $p->cbar_rate])>{{ rate_fmt($p->applied_rate) }}</td>
                        <td data-label="AZN (bank)" class="num">{{ money($p->amount_azn) }}<div class="text-[11px] text-faint">CBAR: {{ money($p->cbar_amount_azn) }}</div></td>
                        <td data-label="Fərq" @class(['num text-xs', 'text-danger' => $diff < 0, 'text-success' => $diff > 0])>{{ $diff > 0 ? '+' : '' }}{{ money($diff) }}</td>
                        <td class="text-right whitespace-nowrap">
                            @can('bank.view')<a href="{{ route('bank.transactions.show', $p) }}" class="btn btn-ghost btn-icon btn-sm" aria-label="Bank əməliyyatına bax" title="Bank əməliyyatı"><x-icon name="external" class="size-4"/></a>@endcan
                            @can('bank.delete')
                                <form method="POST" action="{{ route('deals.payments.destroy', [$deal, $p]) }}" class="inline" data-confirm="Mədaxil ({{ money($p->amount, $p->currency) }}) silinsin? Bank əməliyyatı da silinəcək." data-confirm-action="Sil">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="Mədaxili sil"><x-icon name="trash" class="size-4"/></button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="px-5 py-5 text-sm text-muted">Hələ ödəniş daxil olmayıb.</p>
    @endif
</section>
