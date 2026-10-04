@php
    $editing = $expense->exists;
    $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->bank_name.' · '.$a->name, 'currency' => $a->currency, 'balance' => $a->currentBalance()])->values();
    $v = fn ($k, $d = null) => old($k, $expense->{$k} instanceof \DateTimeInterface ? $expense->{$k}->format('Y-m-d') : ($expense->{$k} ?? $d));
@endphp
<x-layouts.app :title="$editing ? 'Xərci redaktə et' : 'Yeni xərc'">
    <x-page-header :title="$editing ? 'Xərci redaktə et' : 'Yeni xərc'" icon="receipt" :back="route('expenses.index')"/>
    @include('expenses._tabs')

    <form method="POST" action="{{ $editing ? route('expenses.update', $expense) : route('expenses.store') }}" class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start max-w-6xl"
          x-data="{
            status: @js($v('status', 'paid')),
            method: @js($v('payment_method', 'bank') ?: 'bank'),
            currency: @js($v('currency', 'AZN')),
            amount: @js((string) $v('amount', '')),
            paidAt: @js($v('paid_at', today()->toDateString())),
            accounts: @js($accountsData),
            account: @js((string) $v('bank_account_id', '')),
            options() { return this.accounts.filter(a => a.currency === this.currency); },
            current() { return this.accounts.find(a => String(a.id) === String(this.account)); },
            sync() { if (!this.options().some(a => String(a.id) === String(this.account))) this.account = this.options()[0] ? String(this.options()[0].id) : ''; },
            num(v) { return parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')) || 0; },
            fmt: (v) => glaustFmt.fmt(v, 2),
          }" x-init="sync(); $watch('currency', () => sync())">
        @csrf @if($editing) @method('PUT') @endif
        <div class="space-y-6 min-w-0">
            <section class="card p-6 space-y-4">
                <h2 class="text-base font-semibold">{{ __('Xərc') }}</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-field :label="__('Tarix')" name="expense_date" required><input type="date" name="expense_date" value="{{ $v('expense_date') }}" class="input @error('expense_date') is-invalid @enderror" required></x-field>
                    <x-field :label="__('Kateqoriya')" name="category_id">
                        <select name="category_id" class="input @error('category_id') is-invalid @enderror">
                            <option value="">{{ __('— Kateqoriyasız —') }}</option>
                            @foreach($categories as $c)<option value="{{ $c->id }}" @selected((string) $v('category_id') === (string) $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                        <a href="{{ route('expenses.categories') }}" class="text-[11px] text-brand-ink hover:underline">{{ __('+ Kateqoriya əlavə et') }}</a>
                    </x-field>
                </div>
                <x-field :label="__('Təsvir')" name="description" required><input name="description" value="{{ $v('description') }}" maxlength="255" placeholder="{{ __('Məs: Ofis icarəsi, oktyabr') }}" class="input @error('description') is-invalid @enderror" required></x-field>
                <div class="grid sm:grid-cols-[1fr_140px] gap-4">
                    <x-field :label="__('Məbləğ')" name="amount" required><input name="amount" x-model="amount" inputmode="decimal" class="input font-mono text-right @error('amount') is-invalid @enderror" required></x-field>
                    <x-field :label="__('Valyuta')" name="currency" required>
                        <select name="currency" x-model="currency" class="input">@foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                    </x-field>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-combobox name="counterparty_id" :label="__('Kimə (kontragent)')" :url="route('ajax.lookup', 'counterparties')" :value="$expense->counterparty_id" :display="$expense->counterparty?->name" :placeholder="__('İstəyə bağlı')"/>
                    <x-combobox name="project_id" :label="__('Layihə')" :url="route('ajax.lookup', 'projects')" :value="$expense->project_id" :display="$expense->project?->name" :placeholder="__('İstəyə bağlı')"/>
                </div>
                @if($expense->deal_id)<input type="hidden" name="deal_id" value="{{ $expense->deal_id }}"><p class="text-xs text-muted">{{ __('Trade:') }} <b>{{ $expense->deal?->code }}</b></p>@endif
                <div class="grid sm:grid-cols-2 gap-4">
                    <x-field :label="__('İstinad / sənəd №')" name="reference"><input name="reference" value="{{ $v('reference') }}" class="input font-mono"></x-field>
                    <x-field :label="__('Qeyd')" name="notes"><input name="notes" value="{{ $v('notes') }}" class="input"></x-field>
                </div>
            </section>
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-6 space-y-4">
                <h2 class="text-base font-semibold">{{ __('Ödəniş') }}</h2>
                <input type="hidden" name="status" :value="status">
                <div class="segmented" role="radiogroup" aria-label="{{ __('Ödəniş statusu') }}">
                    <label :class="status === 'paid' && 'is-on'"><input type="radio" value="paid" x-model="status" class="sr-only"> {{ __('Ödənilib') }}</label>
                    <label :class="status === 'unpaid' && 'is-on'"><input type="radio" value="unpaid" x-model="status" class="sr-only"> {{ __('Ödənilməyib') }}</label>
                </div>

                <div x-show="status === 'unpaid'" x-cloak>
                    <x-field :label="__('Son ödəniş tarixi')" name="due_date"><input type="date" name="due_date" value="{{ $v('due_date') }}" :disabled="status !== 'unpaid'" class="input"></x-field>
                    <p class="text-[11px] text-muted mt-1">{{ __('Ödəniləndə «Ödə» ilə ödəniş üsulunu seçəcəksiniz.') }}</p>
                </div>

                <div x-show="status === 'paid'" class="space-y-4">
                    <input type="hidden" name="payment_method" :value="status === 'paid' ? method : ''">
                    <div class="segmented" role="radiogroup" aria-label="{{ __('Ödəniş üsulu') }}">
                        <label :class="method === 'cash' && 'is-on'"><input type="radio" value="cash" x-model="method" class="sr-only"> {{ __('Nağd') }}</label>
                        <label :class="method === 'bank' && 'is-on'"><input type="radio" value="bank" x-model="method" class="sr-only"> {{ __('Hesabdan köçürmə') }}</label>
                    </div>
                    @error('payment_method')<p class="field-error">{{ $message }}</p>@enderror
                    <x-field :label="__('Ödəniş tarixi')" name="paid_at" required><input type="date" name="paid_at" x-model="paidAt" :disabled="status !== 'paid'" max="{{ today()->toDateString() }}" class="input @error('paid_at') is-invalid @enderror"></x-field>

                    <div x-show="method === 'bank'" class="space-y-2">
                        <x-field :label="__('Bank hesabı')" name="bank_account_id" required>
                            <select name="bank_account_id" x-model="account" :disabled="status !== 'paid' || method !== 'bank'" class="input @error('bank_account_id') is-invalid @enderror">
                                <template x-for="a in options()" :key="a.id"><option :value="String(a.id)" x-text="a.label + ' (' + a.currency + ')'"></option></template>
                            </select>
                        </x-field>
                        <p class="text-[11px] text-saffron" x-show="!options().length" x-cloak><span x-text="currency"></span> {{ __('valyutasında aktiv bank hesabı yoxdur.') }}</p>
                        <div class="rounded-xl bg-surface-2 px-3 py-2.5 text-xs space-y-1" x-show="current()">
                            <div class="flex items-center gap-1.5 text-ink"><x-icon name="arrow-up-right" class="size-3.5 text-danger"/> <span><b x-text="paidAt ? paidAt.split('-').reverse().join('.') : ''"></b> {{ __('tarixində hesabdan avtomatik silinəcək') }}</span></div>
                            <div class="text-muted">{{ __('Qalıq:') }} <span class="font-mono" x-text="current() && fmt(current().balance)"></span> → <span class="font-mono" :class="current() && current().balance - num(amount) < 0 ? 'text-danger' : 'text-ink'" x-text="current() && fmt(current().balance - num(amount))"></span> <span x-text="currency"></span></div>
                        </div>
                    </div>
                    <p class="text-[11px] text-muted" x-show="method === 'cash'" x-cloak>{{ __('Nağd ödəniş bank hesablarına təsir etmir.') }}</p>
                </div>
            </section>

            <div class="flex flex-wrap gap-3">
                <button class="btn btn-primary flex-1"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
                @unless($editing)<button name="another" value="1" class="btn btn-secondary">{{ __('Saxla və yenisi') }}</button>@endunless
            </div>
        </aside>
    </form>
</x-layouts.app>
