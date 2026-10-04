{{-- Köçürmə şərtləri — inside an x-data="logisticsPay(...)" scope. Parts post as parts[i][...]. --}}
<div class="space-y-4">
    <div>
        <span class="field-label">Necə ödəniləcək?</span>
        <div class="grid grid-cols-3 gap-1 p-1 rounded-[10px] bg-surface-2" role="radiogroup" aria-label="Köçürmə şərtləri">
            @foreach(['RUB' => 'Hamısı rubl ilə', 'EUR' => 'Hamısı avro ilə', 'split' => 'Başqa şərtlərlə'] as $k => $l)
                <button type="button" class="h-9 rounded-lg text-[13px] font-medium transition-colors" @click="setTerms('{{ $k }}')"
                        :class="terms === '{{ $k }}' ? 'bg-surface text-brand-ink shadow-[var(--shadow-card)]' : 'text-ink-2 hover:text-ink'" :aria-pressed="terms === '{{ $k }}'">{{ $l }}</button>
            @endforeach
        </div>
        <p class="text-[11px] text-muted mt-1" x-show="terms === 'split'">Məs: yarı rubl yarı avro, və ya eyni valyuta iki fərqli hesabdan — hissə əlavə edib məbləğləri bölün.</p>
    </div>

    <template x-for="(p, i) in parts" :key="i">
        <div class="rounded-xl border border-line overflow-hidden">
            <div class="flex items-center justify-between gap-2 px-4 py-2 bg-surface-2/60 border-b border-line">
                <span class="text-sm font-semibold"><span x-text="i + 1"></span>-ci hissə <span class="font-normal text-muted text-xs" x-text="p.date ? '· ' + p.date.split('-').reverse().join('.') : ''"></span></span>
                <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" x-show="parts.length > 1" @click="removePart(i)" aria-label="Hissəni sil"><x-icon name="trash" class="size-4"/></button>
            </div>
            <div class="p-4 grid md:grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div>
                        <label class="field-label">Ödəniş tarixi</label>
                        <input type="date" :name="`parts[${i}][payment_date]`" x-model="p.date" @change="load()" :max="today" class="input">
                        <p class="text-[11px] text-muted mt-1">Bu hissənin köçürüldüyü tarix — CBAR kursu və komissiya bu tarixə görə</p>
                    </div>
                    <div class="grid grid-cols-[1fr_96px] gap-2">
                        <div>
                            <label class="field-label">Aktdan pay (<span x-text="currency"></span>)</label>
                            <input :name="`parts[${i}][act_amount]`" x-model="p.share" inputmode="decimal" class="input font-mono text-right">
                        </div>
                        <div>
                            <label class="field-label">Ödəniş</label>
                            <select :name="`parts[${i}][currency]`" x-model="p.currency" @change="changeCurrency(p)" class="input">
                                @foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Bank hesabı (<span x-text="p.currency"></span>)</label>
                        <select :name="`parts[${i}][bank_account_id]`" x-model="p.account" class="input">
                            <template x-for="a in options(p.currency)" :key="a.id"><option :value="String(a.id)" x-text="a.label"></option></template>
                        </select>
                        <p class="text-[11px] text-saffron mt-1" x-show="!options(p.currency).length"><span x-text="p.currency"></span> hesabı yoxdur</p>
                        <p class="text-[11px] mt-1" x-show="account(p) && debit(p) !== null" :class="account(p) && account(p).balance - debit(p) < 0 ? 'text-danger' : 'text-muted'">
                            Qalıq: <span class="font-mono" x-text="account(p) && fmt(account(p).balance)"></span> → <span class="font-mono" x-text="account(p) && debit(p) !== null && fmt(account(p).balance - debit(p))"></span></p>
                    </div>
                    <div x-show="!same(p)">
                        <label class="field-label">Bankın kursu: 1 <span x-text="currency"></span> = ? <span x-text="p.currency"></span></label>
                        <input :name="`parts[${i}][bank_rate]`" x-model="p.bankRate" :disabled="same(p)" inputmode="decimal" :placeholder="cross(p) ? rf(cross(p)) : ''" class="input font-mono text-right">
                        <p class="text-[11px] text-muted mt-1" x-show="cross(p)">CBAR: 1 <span x-text="currency"></span> = <span class="font-mono" x-text="rf(cross(p))"></span> <span x-text="p.currency"></span></p>
                    </div>
                </div>
                <dl class="space-y-1.5 text-sm rounded-lg bg-surface-2/60 p-3 self-start">
                    <div class="flex justify-between gap-2" x-show="!same(p)"><dt class="text-muted">CBAR ilə</dt><dd class="font-mono" x-text="payCbar(p) !== null ? fmt(payCbar(p)) + ' ' + p.currency : '—'"></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-muted">Köçürüləcək</dt><dd class="font-mono font-semibold" x-text="pay(p) !== null ? fmt(pay(p)) + ' ' + p.currency : '—'"></dd></div>
                    <div class="flex justify-between gap-2 text-xs" x-show="!same(p) && diff(p) !== null"><dt class="text-muted">CBAR fərqi</dt>
                        <dd class="font-mono" :class="diff(p) > 0 ? 'text-danger' : (diff(p) < 0 ? 'text-success' : '')" x-text="(diff(p) > 0 ? '−' : (diff(p) < 0 ? '+' : '')) + fmt(Math.abs(diff(p)))"></dd></div>
                    <div class="pt-2 mt-1 border-t border-line">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-muted">Bank komissiyası</dt>
                            <dd><input :name="`parts[${i}][fee_amount]`" :value="p.feeTouched ? p.fee : (ruleFee(p) || '')" @input="p.fee = $event.target.value; p.feeTouched = true" inputmode="decimal" class="input !h-8 w-28 font-mono text-right text-sm" :aria-label="'Komissiya, hissə ' + (i + 1)"></dd>
                        </div>
                        <div class="text-[11px] text-muted mt-1 font-mono" x-show="fee(p)">
                            = <span x-text="feeIn(p, 'RUB') !== null ? fmt(feeIn(p, 'RUB')) + ' RUB' : ''"></span> · <span x-text="feeIn(p, 'AZN') !== null ? fmt(feeIn(p, 'AZN')) + ' AZN' : ''"></span> · <span x-text="feeIn(p, 'EUR') !== null ? fmt(feeIn(p, 'EUR')) + ' EUR' : ''"></span>
                        </div>
                        <div class="text-[11px] text-faint" x-show="rule(p)"><span x-text="rule(p) && String(rule(p).percent).replace('.', ',')"></span>% · ən az <span x-text="rule(p) && fmt(rule(p).min)"></span>, ən çox <span x-text="rule(p) && rule(p).max !== null ? fmt(rule(p).max) : '—'"></span> <span x-text="p.currency"></span>
                            <button type="button" class="underline" x-show="p.feeTouched" @click="p.feeTouched = false; p.fee = ''">qaydaya qaytar</button></div>
                    </div>
                    <div class="flex justify-between gap-2 pt-2 mt-1 border-t border-line"><dt class="font-semibold">Hesabdan silinəcək</dt><dd class="font-mono font-semibold" x-text="debit(p) !== null ? fmt(debit(p)) + ' ' + p.currency : '—'"></dd></div>
                </dl>
            </div>
        </div>
    </template>

    <div class="flex flex-wrap items-center gap-3" x-show="terms">
        <button type="button" class="btn btn-secondary btn-sm" @click="addPart()"><x-icon name="plus" class="size-4"/> Hissə əlavə et</button>
        <span class="text-sm" :class="Math.abs(unallocated()) < 0.01 ? 'text-success' : (unallocated() < 0 ? 'text-danger' : 'text-saffron')">
            Bölünüb: <span class="font-mono" x-text="fmt(allocated()) + ' / ' + fmt(total()) + ' ' + currency"></span>
            <span x-show="unallocated() > 0.009"> · qalır <span class="font-mono" x-text="fmt(unallocated())"></span> (qalığı sonra ödəmək olar)</span>
            <span x-show="unallocated() < -0.009"> · aktdan çoxdur</span>
        </span>
    </div>
</div>
