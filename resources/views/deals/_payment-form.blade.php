{{-- One payment form of a deal: $dir = in (the buyer pays us) or out (we pay the seller). --}}
@php
    $out = $dir === 'out';
    $mine = old('direction', 'in') === $dir;
    $list = $out ? $deal->supplierPayments() : $deal->payments;
    $party = $out ? $deal->supplier?->name : $deal->counterparty?->name;
    $override = $mine && old('applied_rate') !== null && old('applied_rate') !== '';
    $baseCurrency = $out ? ($deal->invoices->where('type', 'supplier')->first()?->currency ?? 'EUR') : ($deal->salesDocuments->where('kind', 'proforma')->first()?->currency ?? $accounts->first()?->currency ?? 'AZN');
    $defCur = $mine ? old('currency', $baseCurrency) : $baseCurrency;
@endphp
@can('bank.create')
    <section class="card mb-6" x-data="{ open: {{ ($mine && $errors->any()) || $list->isEmpty() ? 'true' : 'false' }} }">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" :class="open && 'border-b border-line'">
            <div>
                <h2 class="text-base font-semibold flex items-center gap-2">@if($out)<x-icon name="arrow-up-right" class="size-5 text-danger"/> Satıcıya ödəniş @else<x-icon name="arrow-down-left" class="size-5 text-success"/> Yeni mədaxil @endif</h2>
                <p class="text-xs text-muted">{{ $out ? 'Satıcıya etdiyimiz ödəniş: məbləğ, tarix, valyuta, silindiyi bank hesabımız və bankın tətbiq etdiyi kurs' : 'Alıcının göndərdiyi ödəniş: məbləğ, tarix, valyuta, daxil olduğu bank hesabımız və bankın tətbiq etdiyi kurs' }}</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" @click="open = !open"><x-icon name="plus" class="size-4" x-show="!open"/><span x-text="open ? 'Bağla' : @js($out ? 'Ödəniş əlavə et' : 'Gələn ödəniş əlavə et')"></span></button>
        </header>
        <form method="POST" action="{{ route('deals.payments.store', $deal) }}" x-show="open" x-collapse class="p-5" @if($list->isNotEmpty() && ! ($mine && $errors->any())) x-cloak @endif
              x-data="rateLookup({ currency: @js($defCur), date: @js($mine ? old('transaction_date', today()->toDateString()) : today()->toDateString()), amount: @js((string) ($mine ? old('amount', '') : '')), override: @js($override), applied: @js((string) ($mine ? old('applied_rate', '') : '')) })">
            @csrf
            <input type="hidden" name="direction" value="{{ $dir }}">
            <div class="space-y-5" x-data="{
                    accounts: @js($accountsData),
                    account: @js((string) ($mine ? old('bank_account_id', '') : '')),
                    options() { return this.accounts.filter(a => a.currency === this.currency); },
                    syncAccount() { if (!this.options().some(a => String(a.id) === String(this.account))) this.account = this.options()[0] ? String(this.options()[0].id) : ''; },
                 }" x-init="syncAccount(); $watch('currency', () => syncAccount())">
            <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div>
                    <span class="field-label">{{ $out ? 'Kimə' : 'Kimdən' }}</span>
                    <div class="input flex items-center gap-2 bg-surface-2 text-ink" aria-readonly="true"><x-icon name="building" class="size-4 text-muted shrink-0"/><span class="truncate">{{ $party ?? ($out ? 'Satıcı seçilməyib' : 'Alıcı seçilməyib') }}</span></div>
                    <p class="text-[11px] text-muted mt-1">{{ $out ? 'Məhsulu satan tərəf' : 'Məhsul alan tərəf' }} — avtomatik</p>
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
                <x-field :label="$out ? 'Silindiyi bank hesabımız' : 'Daxil olduğu bank hesabımız'" name="bank_account_id" required>
                    <select name="bank_account_id" x-model="account" class="input @error('bank_account_id') is-invalid @enderror" required>
                        <template x-for="a in options()" :key="a.id"><option :value="String(a.id)" x-text="a.label + ' (' + a.currency + ')'"></option></template>
                    </select>
                    <p class="text-[11px] text-saffron mt-1" x-show="!options().length" x-cloak>
                        <span x-text="currency"></span> valyutasında aktiv bank hesabı yoxdur. @can('bank.create')<a href="{{ route('bank.accounts.index') }}" class="underline">Hesab əlavə edin</a>@endcan
                    </p>
                </x-field>
                <div class="grid grid-cols-2 gap-4">
                    <x-field label="İstinad / ödəniş tapşırığı №" name="reference"><input name="reference" value="{{ $mine ? old('reference') : '' }}" class="input font-mono"></x-field>
                    <x-field label="Təyinat" name="purpose"><input name="purpose" value="{{ $mine ? old('purpose') : '' }}" placeholder="Tədarük {{ $deal->code }} üzrə ödəniş" class="input"></x-field>
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
                <button class="btn btn-primary" :disabled="!options().length"><x-icon name="check" class="size-4"/> {{ $out ? 'Ödənişi qeydə al' : 'Mədaxili qeydə al' }}</button>
            </div>
            </div>
        </form>
    </section>
@endcan
