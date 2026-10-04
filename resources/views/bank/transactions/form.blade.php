@php
    $editing = $tx->exists;
    $accountData = $accounts->mapWithKeys(fn ($a) => [$a->id => ['currency' => $a->currency, 'label' => $a->name.' · '.$a->bank_name.' ('.$a->currency.')']]);
    $selectedAccount = old('bank_account_id', $tx->bank_account_id ?? $accounts->first()?->id);
    $override = (bool) old('override_rate', $editing && $tx->currency !== 'AZN' && (float) $tx->applied_rate !== (float) $tx->cbar_rate);
@endphp
<x-layouts.app :title="$editing ? 'Əməliyyatı redaktə et' : 'Yeni bank əməliyyatı'">
    <x-page-header :title="$editing ? 'Əməliyyatı redaktə et' : 'Yeni bank əməliyyatı'" :back="$editing ? route('bank.transactions.show', $tx) : route('bank.transactions.index')"/>

    @unless($editing)
        <nav class="inline-flex p-1 rounded-xl bg-surface border border-line mb-6" aria-label="{{ __('Əməliyyat növü') }}">
            <a href="{{ route('bank.transactions.create', ['direction' => 'in']) }}" @class(['btn btn-sm', 'bg-success-soft text-success' => $mode === 'regular' && $tx->direction === 'in', 'btn-ghost' => ! ($mode === 'regular' && $tx->direction === 'in')])><x-icon name="arrow-down-left" class="size-4"/> {{ __('Mədaxil') }}</a>
            <a href="{{ route('bank.transactions.create', ['direction' => 'out']) }}" @class(['btn btn-sm', 'bg-danger-soft text-danger' => $mode === 'regular' && $tx->direction === 'out', 'btn-ghost' => ! ($mode === 'regular' && $tx->direction === 'out')])><x-icon name="arrow-up-right" class="size-4"/> {{ __('Məxaric') }}</a>
            <a href="{{ route('bank.transactions.create', ['mode' => 'transfer']) }}" @class(['btn btn-sm', 'bg-brand-soft text-brand-ink' => $mode === 'transfer', 'btn-ghost' => $mode !== 'transfer'])><x-icon name="transfer" class="size-4"/> {{ __('Köçürmə / konvertasiya') }}</a>
        </nav>
    @endunless

    @if($mode === 'transfer')
        <form method="POST" action="{{ route('bank.transactions.store') }}" class="max-w-3xl"
              x-data="{ accounts: @js($accountData), from: @js((string) old('from_account_id', $accounts->first()?->id)), to: @js((string) old('to_account_id', $accounts->skip(1)->first()?->id)), out: @js(old('amount_out')), inn: @js(old('amount_in')),
                        get fromCur() { return this.accounts[this.from]?.currency }, get toCur() { return this.accounts[this.to]?.currency },
                        get conversion() { return this.fromCur && this.toCur && this.fromCur !== this.toCur },
                        get implied() { const o = parseFloat(String(this.out).replace(',', '.')), i = parseFloat(String(this.inn).replace(',', '.')); return o > 0 && i > 0 ? (i / o) : null } }">
            @csrf
            <input type="hidden" name="mode" value="transfer">
            <section class="card p-6 space-y-5">
                <div class="grid sm:grid-cols-[1fr_auto_1fr] gap-4 items-end">
                    <x-field :label="__('Göndərən hesab')" name="from_account_id" :required="true">
                        <select name="from_account_id" id="from_account_id" class="input" x-model="from" required>
                            @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->bank_name }} ({{ $a->currency }})</option>@endforeach
                        </select>
                    </x-field>
                    <span class="hidden sm:grid place-items-center size-10 rounded-full bg-brand-soft text-brand mb-0.5"><x-icon name="arrow-right" class="size-5"/></span>
                    <x-field :label="__('Alan hesab')" name="to_account_id" :required="true">
                        <select name="to_account_id" id="to_account_id" class="input" x-model="to" required>
                            @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->bank_name }} ({{ $a->currency }})</option>@endforeach
                        </select>
                    </x-field>
                </div>
                <div class="grid sm:grid-cols-3 gap-4">
                    <x-input name="transaction_date" type="date" :label="__('Tarix')" :value="today()" required :max="today()->format('Y-m-d')"/>
                    <x-field :label="__('Silinən məbləğ')" name="amount_out" :required="true">
                        <div class="relative"><input name="amount_out" id="amount_out" x-model="out" class="input font-mono text-right pr-14 @error('amount_out') is-invalid @enderror" inputmode="decimal" required>
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-mono text-muted" x-text="fromCur"></span></div>
                    </x-field>
                    <x-field :label="__('Daxil olan məbləğ')" name="amount_in" x-show="conversion" x-cloak>
                        <div class="relative"><input name="amount_in" id="amount_in" x-model="inn" class="input font-mono text-right pr-14 @error('amount_in') is-invalid @enderror" inputmode="decimal" :required="conversion">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-mono text-muted" x-text="toCur"></span></div>
                    </x-field>
                </div>
                <p x-show="conversion" x-cloak class="text-sm text-muted rounded-lg bg-surface-2 px-4 py-3">
                    <b class="text-ink">{{ __('Konvertasiya:') }}</b> <span x-text="fromCur"></span> → <span x-text="toCur"></span>{{ __('.
                    Faktiki kurs:') }} <span class="font-mono text-ink" x-text="implied ? implied.toFixed(6).replace('.', ',') : '—'"></span>{{ __('.
                    Hər iki tərəf öz CBAR məzənnəsi ilə qiymətləndirilir, fərq «kurs fərqi» hesabatında görünür.') }}
                </p>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-input name="purpose" :label="__('Təyinat')" :placeholder="__('Məs: Valyuta alışı')"/>
                    <x-input name="reference" :label="__('Sənəd №')"/>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('bank.transactions.index') }}" class="btn btn-secondary">{{ __('Ləğv et') }}</a>
                    <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Qeyd et') }}</button>
                </div>
            </section>
        </form>
    @else
        <form method="POST" action="{{ $editing ? route('bank.transactions.update', $tx) : route('bank.transactions.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start"
              x-data="{ accounts: @js($accountData), account: @js((string) $selectedAccount) }">
            @csrf
            @if($editing) @method('PUT') @endif
            <input type="hidden" name="mode" value="regular">
            <input type="hidden" name="direction" value="{{ old('direction', $tx->direction) }}">

            <div class="space-y-6 min-w-0">
                <section class="card p-6" x-data="rateLookup({ currency: accounts[account]?.currency || 'AZN', date: @js(old('transaction_date', $tx->transaction_date?->format('Y-m-d'))), amount: @js((string) old('amount', $tx->amount)), override: @js($override), applied: @js((string) old('applied_rate', $override ? $tx->applied_rate : '')) })"
                         x-effect="currency = accounts[account]?.currency || 'AZN'">
                    <div class="flex items-center gap-3 mb-5">
                        <span @class(['grid place-items-center size-10 rounded-xl', 'bg-success-soft text-success' => $tx->direction === 'in', 'bg-danger-soft text-danger' => $tx->direction === 'out'])>
                            <x-icon :name="$tx->direction === 'in' ? 'arrow-down-left' : 'arrow-up-right'" class="size-5"/>
                        </span>
                        <h2 class="text-base font-semibold">{{ $tx->direction === 'in' ? 'Mədaxil' : 'Məxaric' }}</h2>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        @if($editing)
                            <x-field :label="__('Hesab')"><div class="input flex items-center bg-surface-2">{{ $tx->account->name }} ({{ $tx->currency }})</div></x-field>
                        @else
                            <x-field :label="__('Hesab')" name="bank_account_id" :required="true">
                                <select name="bank_account_id" id="bank_account_id" class="input" x-model="account" required>
                                    @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} · {{ $a->bank_name }} ({{ $a->currency }})</option>@endforeach
                                </select>
                            </x-field>
                        @endif
                        <x-input name="transaction_date" type="date" :label="__('Tarix')" :value="$tx->transaction_date" required x-model="date" :max="today()->format('Y-m-d')"/>
                        <x-field :label="__('Məbləğ')" name="amount" :required="true" class="sm:col-span-2">
                            <div class="relative">
                                <input name="amount" id="amount" x-model="amount" class="input h-12 text-lg font-mono text-right pr-16 @error('amount') is-invalid @enderror" inputmode="decimal" required placeholder="0,00">
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm font-mono text-muted" x-text="currency"></span>
                            </div>
                        </x-field>
                    </div>

                    <div class="mt-4 rounded-xl border border-line bg-surface-2 p-4 text-sm space-y-3" x-show="currency !== 'AZN'" x-cloak>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-muted">{{ __('CBAR məzənnəsi (') }}<span x-text="date ? date.split('-').reverse().join('.') : ''"></span>)</span>
                            <span class="font-mono" x-show="!loading" x-text="rate(cbar)"></span><span x-show="loading" class="text-muted">{{ __('yüklənir…') }}</span>
                        </div>
                        <p x-show="error" class="text-saffron text-xs flex items-center gap-1"><x-icon name="alert" class="size-3.5"/><span x-text="error"></span></p>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="override_rate" value="1" class="checkbox" x-model="override"> {{ __('Bank fərqli məzənnə tətbiq edib') }}
                        </label>
                        <div x-show="override" x-collapse>
                            <input name="applied_rate" x-model="applied" class="input font-mono text-right" placeholder="{{ __('Bankın faktiki məzənnəsi') }}" inputmode="decimal" aria-label="{{ __('Bank məzənnəsi') }}">
                            @error('applied_rate')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-line">
                            <span class="text-muted">{{ __('AZN ekvivalenti') }}</span>
                            <span class="font-mono font-semibold text-ink" x-text="money(azn) + ' ₼'"></span>
                        </div>
                        <div x-show="override && cbar" class="flex items-center justify-between text-xs">
                            <span class="text-muted">{{ __('CBAR ilə fərq (kurs fərqi)') }}</span>
                            <span class="font-mono" :class="(azn - cbarAzn) * ({{ $tx->direction === 'in' ? 1 : -1 }}) >= 0 ? 'text-success' : 'text-danger'" x-text="money((azn - cbarAzn) * ({{ $tx->direction === 'in' ? 1 : -1 }})) + ' ₼'"></span>
                        </div>
                    </div>
                </section>

                <section class="card p-6">
                    <h2 class="text-base font-semibold mb-5">{{ __('Bağlılıqlar') }}</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-combobox name="counterparty_id" :label="__('Kontragent')" :url="route('ajax.lookup', 'counterparties')" :value="$tx->counterparty_id" :display="$tx->counterparty?->name" :placeholder="__('Müştəri / təchizatçı')"/>
                        <x-combobox name="contract_id" :label="__('Müqavilə')" :url="route('ajax.lookup', 'contracts')" :value="$tx->contract_id" :display="$tx->contract?->number" :placeholder="__('Müqavilə (istəyə bağlı)')" :hint="__('Müqavilə seçilsə, kontragent ondan götürülür.')"/>
                        <x-combobox name="project_id" :label="__('Layihə')" :url="route('ajax.lookup', 'projects')" :value="$tx->project_id" :display="$tx->project?->name" :placeholder="__('Layihə (istəyə bağlı)')"/>
                        <x-select name="category_id" :label="__('Kateqoriya')" :options="$categories" :value="$tx->category_id" :placeholder="__('— seçin —')"/>
                        <x-input name="purpose" :label="__('Təyinat')" :value="$tx->purpose" wrapper="sm:col-span-2" :placeholder="__('Ödəniş tapşırığındakı təyinat')"/>
                        <x-input name="reference" :label="__('Sənəd / ödəniş tapşırığı №')" :value="$tx->reference"/>
                    </div>
                </section>
            </div>

            <aside class="space-y-3 lg:sticky lg:top-24">
                <button class="btn btn-primary w-full h-11"><x-icon name="check" class="size-4"/> {{ $editing ? 'Yadda saxla' : 'Qeyd et' }}</button>
                @unless($editing)
                    <button name="another" value="1" class="btn btn-secondary w-full">{{ __('Qeyd et və yenisini əlavə et') }}</button>
                @endunless
                <a href="{{ $editing ? route('bank.transactions.show', $tx) : route('bank.transactions.index') }}" class="btn btn-ghost w-full">{{ __('Ləğv et') }}</a>
                <p class="text-xs text-muted px-1 pt-2">{{ __('Məzənnə Mərkəzi Bankın həmin tarixə rəsmi məzənnəsidir. Tapılmasa, əməliyyat yadda saxlanmır — təxmini məzənnə istifadə edilmir.') }}</p>
            </aside>
        </form>
    @endif
</x-layouts.app>
