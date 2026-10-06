{{-- Köçürmə şərtləri — inside an x-data="logisticsPay(...)" scope. Parts post as parts[i][...].
     A part = an amount in its own currency on its own date; how much of the act it settles is
     amount / bank rate (estimated at CBAR of that date until the bank rate is entered). --}}
<div class="space-y-4">
    {{-- What has to be paid, and what is left after the parts below --}}
    <div class="rounded-xl border border-line bg-surface px-4 py-3 grid sm:grid-cols-3 gap-3">
        <div><div class="text-xs text-muted">{{ __('Ödəniləcək məbləğ') }}</div><div class="font-mono text-lg font-semibold" x-text="fmt(total()) + ' ' + currency"></div></div>
        <div><div class="text-xs text-muted">{{ __('Bu ödənişlə bağlanır') }}</div><div class="font-mono text-lg font-semibold" x-text="parts.length ? fmt(settled()) + ' ' + currency : '—'"></div></div>
        <div>
            <div class="text-xs text-muted">{{ __('Qalıq borc') }}</div>
            <div class="font-mono text-lg font-semibold" :class="remaining() < -0.05 ? 'text-danger' : (remaining() > 0.05 ? 'text-saffron' : 'text-success')" x-text="fmt(remaining()) + ' ' + currency"></div>
        </div>
        <p class="sm:col-span-3 text-[11px] text-saffron" x-show="anyEstimate()" x-cloak>
            <x-icon name="alert" class="size-3.5 inline -mt-0.5"/> {{ __('Bu nəticə ödəniş tarixinin') }} <b>{{ __('CBAR kursuna görə təxminidir') }}</b> {{ __('— bankın kursunu daxil edin, qalıq borc dəqiq hesablanacaq.') }}
        </p>
    </div>

    <div>
        <span class="field-label">{{ __('Necə ödəniləcək?') }}</span>
        <div class="grid grid-cols-3 gap-1 p-1 rounded-[10px] bg-surface-2" role="radiogroup" aria-label="{{ __('Köçürmə şərtləri') }}">
            @foreach(['RUB' => __('Hamısı rubl ilə'), 'EUR' => __('Hamısı avro ilə'), 'split' => __('Başqa şərtlərlə')] as $k => $l)
                <button type="button" class="h-9 rounded-lg text-[13px] font-medium transition-colors" @click="setTerms('{{ $k }}')"
                        :class="terms === '{{ $k }}' ? 'bg-surface text-brand-ink shadow-[var(--shadow-card)]' : 'text-ink-2 hover:text-ink'" :aria-pressed="terms === '{{ $k }}'">{{ $l }}</button>
            @endforeach
        </div>
        <p class="text-[11px] text-muted mt-1" x-show="terms === 'split'">{{ __('Məs: yarı rubl yarı avro, və ya eyni valyuta iki fərqli hesabdan — hissə əlavə edib məbləğləri yazın.') }}</p>
    </div>

    <template x-for="(p, i) in parts" :key="i">
        <div class="rounded-xl border border-line overflow-hidden">
            <div class="flex items-center justify-between gap-2 px-4 py-2 bg-surface-2/60 border-b border-line">
                <span class="text-sm font-semibold"><span x-text="i + 1"></span>{{ __('-ci hissə') }} <span class="font-normal text-muted text-xs" x-text="p.date ? '· ' + p.date.split('-').reverse().join('.') : ''"></span></span>
                <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" x-show="parts.length > 1" @click="removePart(i)" aria-label="{{ __('Hissəni sil') }}"><x-icon name="trash" class="size-4"/></button>
            </div>
            <div class="p-4 grid md:grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div>
                        <label class="field-label">{{ __('Ödəniş tarixi') }} <span class="text-danger">*</span></label>
                        <input type="date" :name="`parts[${i}][payment_date]`" x-model="p.date" @change="load()" :max="today" class="input" :class="!p.date && 'border-saffron'">
                        <p class="text-[11px] mt-1" :class="p.date ? 'text-muted' : 'text-saffron'" x-text="p.date ? {{ \Illuminate\Support\Js::from(__('CBAR kursu və komissiya bu tarixə görə')) }} : {{ \Illuminate\Support\Js::from(__('Əvvəlcə ödəniş tarixini seçin — hesablama bu tarixin kursu ilə aparılır')) }}"></p>
                    </div>
                    <div class="grid grid-cols-[1fr_96px] gap-2">
                        <div>
                            <label class="field-label">{{ __('Ödənilən məbləğ (') }}<span x-text="p.currency"></span>)</label>
                            <input :name="`parts[${i}][amount]`" :value="amountShown(p)" @input="setAmount(p, $event.target.value)" :disabled="!p.date" inputmode="decimal" class="input font-mono text-right">
                        </div>
                        <div>
                            <label class="field-label">{{ __('Valyuta') }}</label>
                            <select :name="`parts[${i}][currency]`" x-model="p.currency" @change="changeCurrency(p)" class="input">
                                @foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">{{ __('Bank hesabı (') }}<span x-text="p.currency"></span>)</label>
                        <select :name="`parts[${i}][bank_account_id]`" x-model="p.account" class="input">
                            <template x-for="a in options(p.currency)" :key="a.id"><option :value="String(a.id)" x-text="a.label"></option></template>
                        </select>
                        <p class="text-[11px] text-saffron mt-1" x-show="!options(p.currency).length"><span x-text="p.currency"></span> {{ __('hesabı yoxdur') }}</p>
                        <p class="text-[11px] mt-1" x-show="account(p) && debit(p) !== null" :class="account(p) && account(p).balance - debit(p) < 0 ? 'text-danger' : 'text-muted'">
                            {{ __('Qalıq:') }} <span class="font-mono" x-text="account(p) && fmt(account(p).balance)"></span> → <span class="font-mono" x-text="account(p) && debit(p) !== null && fmt(account(p).balance - debit(p))"></span></p>
                    </div>
                    {{-- the bank's rate as the bank gives it: "1 AZN = ? RUB"; the act currency goes through CBAR of the day --}}
                    <div x-show="!same(p) && perAzn(p)">
                        <label class="field-label">{{ __('Bankın kursu: 1 AZN = ?') }} <span x-text="p.currency"></span> <span class="text-danger">*</span></label>
                        <input :name="`parts[${i}][bank_rate_azn]`" x-model="p.bankRateAzn" :disabled="same(p) || !p.date || !perAzn(p)" inputmode="decimal" :placeholder="rate(p.currency, p.date) ? rf(1 / rate(p.currency, p.date)) : ''" class="input font-mono text-right" :class="p.date && !bank(p) && 'border-saffron'">
                        <p class="text-[11px] text-muted mt-1" x-show="rate(p.currency, p.date)">CBAR (<span x-text="p.date && p.date.split('-').reverse().join('.')"></span>): 1 AZN = <span class="font-mono" x-text="rf(1 / rate(p.currency, p.date))"></span> <span x-text="p.currency"></span></p>
                        <p class="text-[11px] text-muted" x-show="bank(p)">= 1 <span x-text="currency"></span> = <span class="font-mono text-ink" x-text="rf(bank(p))"></span> <span x-text="p.currency"></span> <span class="text-faint">(CBAR <span x-text="currency"></span> <span class="font-mono" x-text="rf(rate(currency, p.date))"></span> ₼ × <span class="font-mono" x-text="num(p.bankRateAzn)"></span>)</span></p>
                    </div>
                    <div x-show="!same(p) && !perAzn(p)">
                        <label class="field-label">{{ __('Bankın kursu: 1') }} <span x-text="currency"></span> = ? <span x-text="p.currency"></span> <span class="text-danger">*</span></label>
                        <input :name="`parts[${i}][bank_rate]`" x-model="p.bankRate" :disabled="same(p) || !p.date || perAzn(p)" inputmode="decimal" :placeholder="cross(p) ? rf(cross(p)) : ''" class="input font-mono text-right" :class="p.date && !bank(p) && 'border-saffron'">
                        <p class="text-[11px] text-muted mt-1" x-show="cross(p)">CBAR (<span x-text="p.date && p.date.split('-').reverse().join('.')"></span>): 1 <span x-text="currency"></span> = <span class="font-mono" x-text="rf(cross(p))"></span> <span x-text="p.currency"></span></p>
                    </div>
                </div>
                <dl class="space-y-1.5 text-sm rounded-lg bg-surface-2/60 p-3 self-start">
                    <div class="flex justify-between gap-2"><dt class="text-muted">{{ __('Köçürülür') }}</dt><dd class="font-mono font-semibold" x-text="pay(p) !== null ? fmt(pay(p)) + ' ' + p.currency : '—'"></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted">{{ __('Borcdan bağlanır') }}</dt>
                        <dd class="font-mono font-semibold text-right"><span x-text="covered(p) !== null ? fmt(covered(p)) + ' ' + currency : '—'"></span>
                            <span class="block text-[10px] font-sans font-normal text-saffron" x-show="estimated(p) && covered(p) !== null">{{ __('CBAR kursuna görə təxmini') }}</span></dd></div>
                    <div class="flex justify-between gap-2 text-xs" x-show="bank(p) && !same(p) && coveredCbar(p) !== null"><dt class="text-muted">{{ __('CBAR ilə bağlanardı') }}</dt><dd class="font-mono" x-text="fmt(coveredCbar(p)) + ' ' + currency"></dd></div>
                    <div class="flex justify-between gap-2 text-xs" x-show="diff(p) !== null"><dt class="text-muted">{{ __('CBAR fərqi') }}</dt>
                        <dd class="font-mono" :class="diff(p) > {{ __('0 ? \'text-danger\' : (diff(p)') }} < 0 ? 'text-success' : '')" x-text="(diff(p) > {{ __('0 ? \'−\' : (diff(p)') }} < 0 ? '+' : '')) + fmt(Math.abs(diff(p))) + ' ' + p.currency"></dd></div>
                    <div class="pt-2 mt-1 border-t border-line">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-muted">{{ __('Bank komissiyası') }}</dt>
                            <dd><input :name="`parts[${i}][fee_amount]`" :value="p.feeTouched ? p.fee : (ruleFee(p) || '')" @input="p.fee = $event.target.value; p.feeTouched = true" inputmode="decimal" class="input !h-8 w-28 font-mono text-right text-sm" :aria-label="{{ \Illuminate\Support\Js::from(__('Komissiya, hissə ')) }} + (i + 1)"></dd>
                        </div>
                        <div class="text-[11px] text-muted mt-1 font-mono" x-show="fee(p)">
                            = <span x-text="feeIn(p, 'RUB') !== null ? fmt(feeIn(p, 'RUB')) + ' RUB' : ''"></span> · <span x-text="feeIn(p, 'AZN') !== null ? fmt(feeIn(p, 'AZN')) + ' AZN' : ''"></span> · <span x-text="feeIn(p, 'EUR') !== null ? fmt(feeIn(p, 'EUR')) + ' EUR' : ''"></span>
                        </div>
                        <div class="text-[11px] text-faint" x-show="rule(p)"><span x-text="rule(p) && String(rule(p).percent).replace('.', ',')"></span>{{ __('% · ən az') }} <span x-text="rule(p) && fmt(rule(p).min)"></span>{{ __(', ən çox') }} <span x-text="rule(p) && rule(p).max !== null ? fmt(rule(p).max) : '—'"></span> <span x-text="p.currency"></span>
                            <button type="button" class="underline" x-show="p.feeTouched" @click="p.feeTouched = false; p.fee = ''">{{ __('qaydaya qaytar') }}</button></div>
                        {{-- which account pays the fee; another currency: at CBAR or at the bank's rate typed for it --}}
                        <div class="mt-2 space-y-1.5">
                            <label class="text-[11px] text-muted" :for="`lp-fee-acc-${i}`">{{ __('Komissiya hansı hesabdan ödənilsin') }}</label>
                            <select :id="`lp-fee-acc-${i}`" :name="`parts[${i}][fee_account_id]`" :value="p.feeAccount || p.account" @change="p.feeAccount = $event.target.value === p.account ? '' : $event.target.value; p.feeByBank = false; load()" class="input !h-8 text-sm">
                                <template x-for="a in accounts" :key="a.id"><option :value="String(a.id)" :selected="String(a.id) === String(p.feeAccount || p.account)" x-text="a.label + ' (' + a.currency + ')'"></option></template>
                            </select>
                            <div class="flex gap-1 p-1 rounded-lg bg-surface border border-line" x-show="feeConvertible(p)">
                                <button type="button" class="flex-1 h-7 rounded-md text-[11px] font-medium" :class="!p.feeByBank ? 'bg-surface-2 text-ink' : 'text-muted'" @click="p.feeByBank = false">{{ __('CBAR kursu ilə') }}</button>
                                <button type="button" class="flex-1 h-7 rounded-md text-[11px] font-medium" :class="p.feeByBank ? 'bg-surface-2 text-ink' : 'text-muted'" @click="p.feeByBank = true">{{ __('Bank kursu ilə hesabla') }}</button>
                            </div>
                            <template x-if="feeBankMode(p)">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-[11px] text-muted">{{ __('Komissiya üçün bankın kursu: 1') }} <span x-text="p.currency"></span> = ? <span x-text="feeCur(p)"></span></span>
                                    <input :name="`parts[${i}][fee_bank_rate]`" x-model="p.feeRate" inputmode="decimal" :placeholder="feeCbar(p) && fee(p) ? rf(feeCbar(p) / fee(p)) : ''" class="input !h-8 w-28 font-mono text-right text-sm" :class="!num(p.feeRate) && 'border-saffron'">
                                </div>
                            </template>
                            <div class="text-[11px] text-muted font-mono" x-show="feeConvertible(p) && feeAcc(p) !== null">= <span class="text-ink font-medium" x-text="fmt(feeAcc(p)) + ' ' + feeCur(p)"></span>
                                <span x-show="!feeBankMode(p)">{{ __('(CBAR)') }}</span>
                                <span x-show="feeBankMode(p)">· CBAR: <span x-text="feeCbar(p) !== null ? fmt(feeCbar(p)) : '—'"></span>
                                    <span x-show="feeDiff(p)" :class="feeDiff(p) > 0 ? 'text-danger' : 'text-success'" x-text="'(' + (feeDiff(p) > 0 ? '−' : '+') + fmt(Math.abs(feeDiff(p))) + ')'"></span></span></div>
                        </div>
                    </div>
                    <div class="flex justify-between gap-2 pt-2 mt-1 border-t border-line"><dt class="font-semibold">{{ __('Hesabdan silinəcək') }}</dt><dd class="font-mono font-semibold" x-text="debit(p) !== null ? fmt(debit(p)) + ' ' + p.currency : '—'"></dd></div>
                    <div class="flex justify-between gap-2" x-show="feeSeparate(p) && fee(p)"><dt class="text-muted">{{ __('Komissiya hesabından silinəcək') }}</dt><dd class="font-mono font-semibold" x-text="feeAcc(p) !== null ? fmt(feeAcc(p)) + ' ' + feeCur(p) : '—'"></dd></div>
                    <div class="flex justify-between gap-2 pt-2 mt-1 border-t border-line" x-show="covered(p) !== null">
                        <dt class="text-muted">{{ __('Bundan sonra qalıq borc') }}</dt>
                        <dd class="font-mono font-semibold" :class="remainingAfter(i) < -0.05 ? 'text-danger' : ''" x-text="fmt(remainingAfter(i)) + ' ' + currency"></dd>
                    </div>
                </dl>
            </div>
        </div>
    </template>

    <div class="flex flex-wrap items-center gap-3" x-show="terms">
        <button type="button" class="btn btn-secondary btn-sm" @click="addPart()"><x-icon name="plus" class="size-4"/> {{ __('Hissə əlavə et') }}</button>
        <span class="text-xs text-muted" x-show="remaining() > 0.05">{{ __('Qalıq borcu sonra «Ödəniş et» ilə ödəmək olar.') }}</span>
        <span class="text-xs text-danger" x-show="remaining() < -0.05">{{ __('Ödənişlər borcdan çoxdur.') }}</span>
    </div>
</div>
