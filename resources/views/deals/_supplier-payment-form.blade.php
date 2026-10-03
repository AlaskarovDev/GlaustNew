{{-- We pay the seller: amount in the invoice currency, from any account (bank's rate when it differs), bank fee as an expense. --}}
@php
    $mine = old('direction') === 'out';
    $sellerDue = \App\Support\DealObligations::for($deal)['seller']['due'];
    $payCur = $mine ? old('currency') : (array_key_first($sellerDue) ?? ($deal->invoices->where('type', 'supplier')->first()?->currency ?? 'EUR'));
    $payAmount = $mine ? old('amount') : ($sellerDue[$payCur] ?? '');
    $fees = config('glaust.bank_fees');
    $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->bank_name.' · '.$a->name, 'currency' => $a->currency, 'balance' => $a->currentBalance()])->values();
    $list = $deal->supplierPayments;
@endphp
@can('bank.create')
<section class="card mb-6" x-data="{ open: {{ ($mine && $errors->any()) || $list->isEmpty() ? 'true' : 'false' }} }">
    <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" :class="open && 'border-b border-line'">
        <div>
            <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="arrow-up-right" class="size-5 text-danger"/> Satıcıya ödəniş</h2>
            <p class="text-xs text-muted">Köçürmə tarixinə görə CBAR kursu, bankın kursu və bank komissiyası (EUR: 0,25%, ən az 25, ən çox 300 EUR)</p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" @click="open = !open"><span x-text="open ? 'Bağla' : 'Ödəniş et'"></span></button>
    </header>
    <form method="POST" action="{{ route('deals.payments.store', $deal) }}" x-show="open" x-collapse class="p-5" @if($list->isNotEmpty() && ! ($mine && $errors->any())) x-cloak @endif
          x-data="{
            date: @js($mine ? old('payment_date') : today()->toDateString()),
            cur: @js($payCur),
            amount: @js((string) $payAmount),
            accounts: @js($accountsData),
            account: @js((string) ($mine ? old('bank_account_id', '') : '')),
            bankRate: @js((string) ($mine ? old('bank_rate', '') : '')),
            fee: @js((string) ($mine ? old('fee_amount', '') : '')), feeTouched: {{ $mine && old('fee_amount') !== null ? 'true' : 'false' }},
            fees: @js($fees),
            rate: { cur: null, acc: null }, loading: false, error: '',
            num(v) { return parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')) || 0; },
            acc() { return this.accounts.find(a => String(a.id) === String(this.account)); },
            accCur() { return this.acc()?.currency; },
            same() { return this.accCur() === this.cur; },
            async one(c) { if (c === 'AZN') return 1; const d = await glaustApi('/ajax/rate?currency=' + c + '&date=' + encodeURIComponent(this.date)); if (!d.ok) throw new Error(d.message); return d.rate; },
            async load() {
                this.error = ''; this.rate = { cur: null, acc: null };
                if (!this.date || !this.accCur()) return;
                this.loading = true;
                try { this.rate = { cur: await this.one(this.cur), acc: await this.one(this.accCur()) }; } catch (e) { this.error = e.message; }
                this.loading = false;
            },
            cross() { return this.rate.cur && this.rate.acc ? this.rate.cur / this.rate.acc : null; },
            appliedRate() { return this.same() ? 1 : this.num(this.bankRate); },
            accCbar() { return this.cross() ? Math.round(this.num(this.amount) * this.cross() * 100) / 100 : null; },
            accBank() { return this.appliedRate() ? Math.round(this.num(this.amount) * this.appliedRate() * 100) / 100 : null; },
            diff() { return this.accCbar() !== null && this.accBank() !== null ? Math.round((this.accBank() - this.accCbar()) * 100) / 100 : null; },
            rule() { return this.fees[this.cur] || null; },
            ruleFee() { const r = this.rule(); if (!r) return 0; return Math.round(Math.min(Math.max(this.num(this.amount) * r.percent / 100, r.minimum), r.maximum ?? Infinity) * 100) / 100; },
            feeValue() { return this.feeTouched ? this.num(this.fee) : this.ruleFee(); },
            feeAcc() { return this.appliedRate() ? Math.round(this.feeValue() * this.appliedRate() * 100) / 100 : null; },
            total() { return this.accBank() !== null && this.feeAcc() !== null ? Math.round((this.accBank() + this.feeAcc()) * 100) / 100 : null; },
            fmt: (v) => glaustFmt.fmt(v, 2), rf: (v) => glaustFmt.fmtRate(v),
          }" x-init="if (!account && accounts.length) { const m = accounts.find(a => a.currency === cur); account = String((m || accounts[0]).id); } load(); $watch('date', () => load()); $watch('cur', () => load()); $watch('account', () => load()); $watch('amount', () => { if (!feeTouched) fee = ''; })">
        @csrf
        <input type="hidden" name="direction" value="out">
        <div class="grid xl:grid-cols-[minmax(0,1fr)_400px] gap-6">
            <div class="space-y-5 min-w-0">
                <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div>
                        <span class="field-label">Kimə</span>
                        <div class="input flex items-center gap-2 bg-surface-2 text-ink" aria-readonly="true"><x-icon name="building" class="size-4 text-muted shrink-0"/><span class="truncate">{{ $deal->supplier?->name ?? 'Satıcı seçilməyib' }}</span></div>
                        <p class="text-[11px] text-muted mt-1">Məhsulu satan tərəf — avtomatik</p>
                    </div>
                    <x-field label="Köçürmə tarixi" name="payment_date" required><input type="date" name="payment_date" x-model="date" max="{{ today()->toDateString() }}" class="input @error('payment_date') is-invalid @enderror" required></x-field>
                    <x-field label="Ödəniş valyutası" name="currency" required>
                        <select name="currency" x-model="cur" class="input">@foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                    </x-field>
                    <x-field label="Məbləğ" name="amount" required>
                        <input name="amount" x-model="amount" inputmode="decimal" class="input font-mono text-right @error('amount') is-invalid @enderror" required>
                        @if($sellerDue)<p class="text-[11px] text-muted mt-1">Qalıq borc: {{ collect($sellerDue)->map(fn ($v, $c) => money($v, $c))->implode(' · ') }}</p>@endif
                    </x-field>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <x-field label="Silinəcək bank hesabımız" name="bank_account_id" required>
                        <select name="bank_account_id" x-model="account" class="input @error('bank_account_id') is-invalid @enderror" required>
                            <template x-for="a in accounts" :key="a.id"><option :value="String(a.id)" x-text="a.label + ' (' + a.currency + ')'"></option></template>
                        </select>
                        <p class="text-[11px] mt-1" x-show="acc() && total() !== null" :class="acc() && acc().balance - total() < 0 ? 'text-danger' : 'text-muted'">
                            Qalıq: <span class="font-mono" x-text="acc() && fmt(acc().balance)"></span> → <span class="font-mono" x-text="acc() && total() !== null && fmt(acc().balance - total())"></span> <span x-text="accCur()"></span></p>
                    </x-field>
                    <div class="grid grid-cols-2 gap-4">
                        <x-field label="İstinad / ödəniş tapşırığı №" name="reference"><input name="reference" value="{{ $mine ? old('reference') : '' }}" class="input font-mono"></x-field>
                        <x-field label="Təyinat" name="purpose"><input name="purpose" value="{{ $mine ? old('purpose') : '' }}" placeholder="Tədarük {{ $deal->code }} üzrə ödəniş" class="input"></x-field>
                    </div>
                </div>
            </div>

            {{-- CBAR vs bank, fee, total debit --}}
            <div class="rounded-xl border border-line overflow-hidden self-start">
                <div class="px-4 py-3 bg-surface-2/60 border-b border-line">
                    <div class="text-xs text-muted">CBAR kursu · <span x-text="date ? date.split('-').reverse().join('.') : '—'"></span></div>
                    <div class="font-mono font-semibold mt-0.5"><span x-show="loading" class="text-faint">yüklənir…</span>
                        <span x-show="!loading && cross()">1 <span x-text="cur"></span> = <span x-text="rf(cross())"></span> <span x-text="accCur()"></span></span></div>
                    <div class="text-[11px] text-danger" x-show="error" x-text="error"></div>
                </div>
                <dl class="px-4 py-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted">CBAR ilə hesabdan</dt><dd class="font-mono" x-text="accCbar() !== null ? fmt(accCbar()) + ' ' + accCur() : '—'"></dd></div>
                </dl>
                <div class="px-4 pb-3" x-show="accCur() && !same()">
                    <label class="field-label" for="sp-rate">Bankın kursu: 1 <span x-text="cur"></span> = ? <span x-text="accCur()"></span> <span class="text-danger">*</span></label>
                    <input id="sp-rate" name="bank_rate" x-model="bankRate" :disabled="same()" inputmode="decimal" :placeholder="cross() ? rf(cross()) : ''" class="input font-mono text-right @error('bank_rate') is-invalid @enderror">
                    @error('bank_rate')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <dl class="px-4 py-3 border-t border-line space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Ödəniş (bank kursu ilə)</dt><dd class="font-mono" x-text="accBank() !== null ? fmt(accBank()) + ' ' + accCur() : '—'"></dd></div>
                    <div class="flex justify-between gap-3" x-show="!same() && diff() !== null"><dt class="text-muted">CBAR ilə fərq</dt>
                        <dd class="font-mono" :class="diff() > 0 ? 'text-danger' : (diff() < 0 ? 'text-success' : '')" x-text="(diff() > 0 ? '−' : (diff() < 0 ? '+' : '')) + fmt(Math.abs(diff())) + ' ' + accCur()"></dd></div>
                </dl>
                <div class="px-4 py-3 border-t border-line space-y-1.5">
                    <label class="field-label" for="sp-fee">Bank komissiyası (<span x-text="cur"></span>)</label>
                    <input id="sp-fee" name="fee_amount" :value="feeTouched ? fee : (ruleFee() || '')" @input="fee = $event.target.value; feeTouched = true" inputmode="decimal" class="input font-mono text-right">
                    <p class="text-[11px] text-muted" x-show="rule()">Qayda: <span x-text="rule() && String(rule().percent).replace('.', ',')"></span>% — ən az <span x-text="rule() && rule().minimum"></span>, ən çox <span x-text="rule() && rule().maximum"></span> <span x-text="cur"></span>
                        <button type="button" class="underline ml-1" x-show="feeTouched" @click="feeTouched = false; fee = ''">qaydaya qaytar</button></p>
                    <p class="text-[11px] text-muted" x-show="!same() && feeAcc()">= <span class="font-mono" x-text="fmt(feeAcc()) + ' ' + accCur()"></span>, «Xərclər»də «Bank komissiyası» kimi yazılacaq</p>
                </div>
                <div class="px-4 py-3 border-t border-line bg-surface-2/60 flex items-baseline justify-between">
                    <span class="text-sm font-semibold">Hesabdan cəmi silinəcək</span>
                    <span class="font-mono text-lg font-semibold" x-text="total() !== null ? fmt(total()) + ' ' + accCur() : '—'"></span>
                </div>
                <div class="p-4">
                    <button class="btn btn-primary w-full" :disabled="total() === null || !num(amount)"><x-icon name="check" class="size-4"/> Ödənişi icra et</button>
                </div>
            </div>
        </div>
    </form>
</section>
@endcan
