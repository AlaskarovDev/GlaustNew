{{-- Step 3 — Logistika: the logistics payment set on the invoices, the logistics company's acts and their payments. --}}
@php
    $acts = $deal->logisticsActs;
    $withLogistics = $supplierInvoices->filter(fn ($i) => $i->hasLogistics());
    $accountsData = $accounts->map(fn ($a) => ['id' => $a->id, 'label' => $a->bank_name.' · '.$a->name, 'currency' => $a->currency, 'balance' => $a->currentBalance()])->values();
    $fees = config('glaust.bank_fees');
    $today = today()->toDateString();
    $failedNew = $errors->any() && old('act_number') !== null;
@endphp

{{-- Logistics payment as set and approved on the invoices --}}
<div class="flex items-baseline justify-between gap-3 mb-3">
    <h2 class="text-sm font-semibold text-ink-2">Fakturada qeyd olunan logistika ödənişi</h2>
</div>
@if($withLogistics->isEmpty())
    <div class="card p-5 mb-6 text-sm text-muted">Satıcının fakturasında logistika xərci hələ daxil edilməyib — «Fakturalar» addımında fakturanı açıb logistika xərcini daxil edin.</div>
@else
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
        @foreach($withLogistics as $inv)
            @php $invActs = $acts->where('invoice_id', $inv->id); @endphp
            <a href="{{ route('invoices.show', $inv) }}" class="card card-hover p-4 block">
                <div class="flex items-start justify-between gap-2">
                    <div><div class="text-xs text-muted">Faktura</div><div class="font-mono font-semibold">{{ $inv->number }}</div></div>
                    <div class="flex flex-wrap justify-end gap-1">
                        <span @class(['badge', 'badge-amber' => $inv->logistics_mode === 'forecast', 'badge-green' => $inv->logistics_mode === 'actual'])>{{ \App\Models\Invoice::LOGISTICS_MODES[$inv->logistics_mode] }}</span>
                        @if($inv->approval_status)@php [$al, $at] = \App\Models\Invoice::APPROVAL_STATUSES[$inv->approval_status]; @endphp<span class="badge badge-{{ $at }}">{{ $al }}</span>@endif
                    </div>
                </div>
                <div class="mt-3 text-xl font-mono font-semibold">{{ money($inv->logistics_amount, $inv->logistics_currency) }}</div>
                <div class="text-xs text-muted mt-1">
                    @if($invActs->isNotEmpty())Aktlar: {!! $invActs->groupBy('currency')->map(fn ($g, $c) => e(money($g->sum('amount'), $c)))->implode(' · ') !!}@else Akt hələ yoxdur @endif
                </div>
            </a>
        @endforeach
    </div>
@endif

{{-- Acts --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <h2 class="text-sm font-semibold text-ink-2">Logistika aktları <span class="font-mono text-muted font-normal">{{ $acts->count() }}</span></h2>
</div>

<div class="space-y-4 mb-6">
    @foreach($acts as $act)
        @php [$sl, $st] = \App\Models\LogisticsAct::STATUSES[$act->status()]; @endphp
        <article class="card overflow-hidden" x-data="{ payOpen: false }">
            <header class="flex flex-wrap items-start gap-3 px-5 py-4 border-b border-line">
                <span class="grid place-items-center size-10 rounded-xl bg-saffron-soft text-saffron shrink-0"><x-icon name="truck" class="size-5"/></span>
                <div class="min-w-0 flex-1">
                    <div class="font-semibold">Akt № {{ $act->act_number }} <span class="text-xs text-muted font-normal">· {{ azdate($act->act_date) }}</span></div>
                    <div class="text-xs text-muted">{{ $act->counterparty?->name ?? 'Logistika şirkəti göstərilməyib' }}
                        @if($act->logistics_invoice_number) · invoys <span class="font-mono">{{ $act->logistics_invoice_number }}</span>@if($act->logistics_invoice_date) ({{ azdate($act->logistics_invoice_date) }})@endif @endif
                        @if($act->invoice) · faktura <span class="font-mono">{{ $act->invoice->number }}</span>@endif</div>
                </div>
                <div class="text-right">
                    <div class="font-mono text-lg font-semibold">{{ money($act->amount, $act->currency) }}</div>
                    <span class="badge badge-{{ $st }}">{{ $sl }}</span>
                </div>
            </header>
            <div class="grid lg:grid-cols-[minmax(0,1fr)_320px]">
                <div class="p-5 space-y-3 min-w-0">
                    @if($act->payments->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="table-g text-[13px]">
                                <thead><tr><th>Tarix</th><th class="!text-right">Aktdan</th><th class="!text-right">Köçürülüb</th><th class="!text-right">Bank / CBAR kursu</th><th class="!text-right">Komissiya</th><th>Hesab</th><th class="w-10"></th></tr></thead>
                                <tbody>
                                @foreach($act->payments as $p)
                                    <tr>
                                        <td class="font-mono text-xs">{{ azdate($p->payment_date) }}</td>
                                        <td class="num">{{ money($p->act_amount, $act->currency) }}</td>
                                        <td class="num font-medium">{{ money($p->amount, $p->currency) }}</td>
                                        <td class="num text-xs">@if($p->currency !== $act->currency){{ rate_fmt($p->bank_rate) }}<div class="text-faint">{{ rate_fmt($p->cbar_cross) }}</div>@else — @endif</td>
                                        <td class="num text-xs">{{ money($p->fee_amount, $p->currency) }}<div class="text-faint">{{ money($p->fee_azn) }} · {{ money($p->fee_eur, 'EUR') }}</div></td>
                                        <td class="text-xs">{{ $p->account?->name }}<div class="text-faint">−{{ money($p->totalDebit(), $p->currency) }}</div></td>
                                        <td class="text-right">
                                            @can('bank.delete')
                                                <form method="POST" action="{{ route('deals.logistics-payments.destroy', [$deal, $p]) }}" data-confirm="Bu ödəniş hissəsi ləğv edilsin? Hesabdan silinmə və komissiya da silinəcək." data-confirm-action="Ləğv et">
                                                    @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="Hissəni ləğv et"><x-icon name="trash" class="size-4"/></button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-muted">Hələ ödəniş edilməyib.
                            @if($act->planned_date) Planlaşdırılan köçürmə: <b class="text-ink">{{ azdate($act->planned_date) }}</b>@if($act->reminder_id) <span class="badge badge-blue ml-1"><x-icon name="bell" class="size-3"/> xatırlatma</span>@endif @endif
                        </p>
                    @endif
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        @if($act->remaining() > 0)
                            @can('bank.create')<button type="button" class="btn btn-primary btn-sm" @click="payOpen = true"><x-icon name="send" class="size-4"/> Ödəniş et</button>@endcan
                            @can('projects.update')
                                <form method="POST" action="{{ route('deals.logistics-acts.remind', [$deal, $act]) }}" class="flex items-center gap-2">
                                    @csrf
                                    <input type="date" name="planned_date" min="{{ $today }}" value="{{ $act->planned_date?->toDateString() }}" class="input !h-8 !w-40 text-sm" aria-label="Köçürmə tarixi" required>
                                    <button class="btn btn-secondary btn-sm"><x-icon name="bell" class="size-4"/> Xatırlatma</button>
                                </form>
                            @endcan
                        @endif
                        @can('projects.delete')
                            <form method="POST" action="{{ route('deals.logistics-acts.destroy', [$deal, $act]) }}" class="ml-auto" data-confirm="Akt {{ $act->act_number }} silinsin? Ödənişləri, komissiyaları və bank hərəkətləri də silinəcək." data-confirm-action="Sil">
                                @csrf @method('DELETE')<button class="btn btn-ghost btn-sm text-danger"><x-icon name="trash" class="size-4"/> Aktı sil</button>
                            </form>
                        @endcan
                    </div>
                </div>
                <aside class="p-5 bg-surface-2/50 lg:border-l border-t lg:border-t-0 border-line text-sm space-y-1.5">
                    <div class="text-xs text-muted mb-1">CBAR · {{ azdate($act->act_date) }}</div>
                    <div class="flex justify-between"><span class="text-muted">AZN</span><span class="font-mono">{{ money($act->amount_azn) }}</span></div>
                    <div class="flex justify-between"><span class="text-muted">RUB</span><span class="font-mono">{{ money($act->amount_rub, 'RUB') }}</span></div>
                    <div class="flex justify-between"><span class="text-muted">EUR</span><span class="font-mono">{{ money($act->amount_eur, 'EUR') }}</span></div>
                    <div class="text-[11px] text-faint pt-1">1 {{ $act->currency }} = {{ rate_fmt($act->cbar_rate) }} ₼ · 1 RUB = {{ rate_fmt($act->cbar_rub) }} ₼ · 1 EUR = {{ rate_fmt($act->cbar_eur) }} ₼</div>
                    <div class="flex justify-between pt-2 mt-1 border-t border-line"><span class="text-muted">Ödənilib</span><span class="font-mono">{{ money($act->paid(), $act->currency) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>Qalıq</span><span class="font-mono">{{ money($act->remaining(), $act->currency) }}</span></div>
                </aside>
            </div>

            {{-- Pay this act --}}
            @can('bank.create')
                <template x-teleport="body">
                <div x-cloak x-show="payOpen" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="payOpen = false">
                    <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="payOpen = false"></div>
                    <form method="POST" action="{{ route('deals.logistics-acts.pay', [$deal, $act]) }}" x-show="payOpen" x-transition.opacity
                          class="relative w-full max-w-3xl max-h-[92vh] card !shadow-[var(--shadow-pop)] flex flex-col" x-trap.noscroll="payOpen"
                          x-data="logisticsPay({ mode: 'pay', currency: @js($act->currency), amount: @js((string) $act->remaining()), today: @js($today), accounts: @js($accountsData), fees: @js($fees) })">
                        @csrf
                        <header class="flex items-start justify-between gap-4 px-6 py-4 border-b border-line">
                            <div><h2 class="text-lg font-semibold">Akt № {{ $act->act_number }} — ödəniş</h2><p class="text-xs text-muted">Qalıq: {{ money($act->remaining(), $act->currency) }} · {{ $act->counterparty?->name }}</p></div>
                            <button type="button" class="btn btn-ghost btn-icon" @click="payOpen = false" aria-label="Bağla"><x-icon name="x" class="size-5"/></button>
                        </header>
                        <div class="overflow-y-auto p-6 space-y-5">
                            <div class="grid sm:grid-cols-2 gap-4">
                                <x-field label="Köçürmə tarixi" name="payment_date" required><input type="date" name="payment_date" x-model="payDate" max="{{ $today }}" class="input" required></x-field>
                                <x-field label="İstinad / ödəniş tapşırığı №" name="reference"><input name="reference" class="input font-mono"></x-field>
                            </div>
                            @include('deals._logistics-terms')
                        </div>
                        <footer class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line">
                            <button type="button" class="btn btn-secondary" @click="payOpen = false">Bağla</button>
                            <button class="btn btn-primary" :disabled="!canSubmit()"><x-icon name="check" class="size-4"/> Ödənişi icra et</button>
                        </footer>
                    </form>
                </div>
                </template>
            @endcan
        </article>
    @endforeach

    @if($acts->isEmpty())
        <div class="card p-6 text-center text-sm text-muted border-dashed">Hələ logistika aktı yoxdur.</div>
    @endif
</div>

{{-- Add an act --}}
@can('projects.update')
    <div x-data="{ open: {{ $failedNew ? 'true' : 'false' }} }">
        <button type="button" class="btn btn-primary" @click="open = true"><x-icon name="plus" class="size-4"/> Logistika aktı əlavə et</button>

        {{-- Teleported to <body>: an animated (transformed) ancestor would trap position:fixed under the sticky header. --}}
        <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="la-title" @keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = false"></div>
            <form method="POST" action="{{ route('deals.logistics-acts.store', $deal) }}" x-show="open" x-transition.opacity
                  class="relative w-full max-w-4xl max-h-[94vh] card !shadow-[var(--shadow-pop)] flex flex-col" x-trap.noscroll="open"
                  x-data="logisticsPay({ mode: 'new', currency: @js(old('currency', $withLogistics->first()?->logistics_currency ?? 'EUR')), amount: @js((string) old('amount', '')), actDate: @js(old('act_date', $today)), today: @js($today), plan: @js(old('payment_plan', 'today')), accounts: @js($accountsData), fees: @js($fees) })">
                @csrf
                <header class="flex items-start justify-between gap-4 px-6 py-4 border-b border-line">
                    <div><h2 id="la-title" class="text-lg font-semibold">Logistika aktı əlavə et</h2><p class="text-xs text-muted">Sövdələşmə {{ $deal->code }} · məbləğ akt tarixinin CBAR kursları ilə hesablanır</p></div>
                    <button type="button" class="btn btn-ghost btn-icon" @click="open = false" aria-label="Bağla"><x-icon name="x" class="size-5"/></button>
                </header>
                <div class="overflow-y-auto p-6 space-y-6">
                    @if($failedNew)
                        <div class="rounded-lg bg-danger-soft/60 text-danger text-sm px-3 py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
                    @endif
                    <div class="grid lg:grid-cols-[minmax(0,1fr)_280px] gap-6">
                        <div class="space-y-4 min-w-0">
                            <x-combobox name="counterparty_id" label="Logistika şirkəti" :url="route('ajax.lookup', 'counterparties')" placeholder="CRM-dən seçin"/>
                            <div class="grid sm:grid-cols-2 gap-4">
                                <x-field label="Akt nömrəsi" name="act_number" required><input name="act_number" value="{{ old('act_number') }}" class="input font-mono" required></x-field>
                                <x-field label="Akt tarixi" name="act_date" required><input type="date" name="act_date" x-model="actDate" max="{{ $today }}" class="input" required></x-field>
                            </div>
                            <div class="grid grid-cols-[1fr_110px] gap-3">
                                <x-field label="Ödənişin məbləği" name="amount" required><input name="amount" x-model="amount" inputmode="decimal" class="input font-mono text-right" required></x-field>
                                <x-field label="Valyuta" name="currency" required><select name="currency" x-model="currency" class="input">@foreach(config('glaust.currencies') as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select></x-field>
                            </div>
                            <div class="grid sm:grid-cols-2 gap-4">
                                <x-field label="Logistikanın invoys nömrəsi" name="logistics_invoice_number"><input name="logistics_invoice_number" value="{{ old('logistics_invoice_number') }}" class="input font-mono" placeholder="varsa"></x-field>
                                <x-field label="Invoys tarixi" name="logistics_invoice_date"><input type="date" name="logistics_invoice_date" value="{{ old('logistics_invoice_date') }}" class="input"></x-field>
                            </div>
                            @if($withLogistics->count() > 1)
                                <x-field label="Hansı fakturanın logistikası" name="invoice_id">
                                    <select name="invoice_id" class="input">@foreach($withLogistics as $inv)<option value="{{ $inv->id }}">{{ $inv->number }} · {{ money($inv->logistics_amount, $inv->logistics_currency) }}</option>@endforeach</select>
                                </x-field>
                            @elseif($withLogistics->count() === 1)
                                <input type="hidden" name="invoice_id" value="{{ $withLogistics->first()->id }}">
                            @endif
                        </div>
                        {{-- CBAR of the act date: amount in AZN, RUB and EUR with the rates used --}}
                        <aside class="rounded-xl border border-line overflow-hidden self-start">
                            <div class="px-4 py-3 bg-surface-2/60 border-b border-line text-xs text-muted">CBAR · <span x-text="actDate ? actDate.split('-').reverse().join('.') : '—'"></span></div>
                            <dl class="px-4 py-3 space-y-2 text-sm">
                                <template x-for="c in ['AZN', 'RUB', 'EUR']" :key="c">
                                    <div class="flex justify-between gap-2"><dt class="text-muted" x-text="c"></dt><dd class="font-mono font-medium" x-text="actIn(c) !== null ? fmt(actIn(c)) : '—'"></dd></div>
                                </template>
                            </dl>
                            <div class="px-4 pb-3 text-[11px] text-faint space-y-0.5">
                                <div x-show="!['AZN', 'RUB', 'EUR'].includes(currency)">1 <span x-text="currency"></span> = <span class="font-mono" x-text="rf(rate(currency, actDate))"></span> ₼</div>
                                <div>1 RUB = <span class="font-mono" x-text="rf(rate('RUB', actDate))"></span> ₼</div>
                                <div>1 EUR = <span class="font-mono" x-text="rf(rate('EUR', actDate))"></span> ₼</div>
                                <div class="text-danger" x-show="errors.rate" x-text="errors.rate"></div>
                            </div>
                        </aside>
                    </div>

                    {{-- When --}}
                    <div class="space-y-3">
                        <span class="field-label">Köçürmə</span>
                        <input type="hidden" name="payment_plan" :value="plan">
                        <div class="segmented max-w-md">
                            <label :class="plan === 'today' && 'is-on'"><input type="radio" value="today" x-model="plan" class="sr-only"> Bu gün ediləcək</label>
                            <label :class="plan === 'later' && 'is-on'"><input type="radio" value="later" x-model="plan" class="sr-only"> Başqa tarixdə</label>
                        </div>
                        <div x-show="plan === 'later'" x-cloak class="flex flex-wrap items-end gap-3">
                            <x-field label="Köçürmə tarixi" name="planned_date"><input type="date" name="planned_date" x-model="plannedDate" :disabled="plan !== 'later'" min="{{ $today }}" class="input"></x-field>
                            <input type="hidden" name="remind" :value="remind ? 1 : 0">
                            <button type="button" class="btn" :class="remind ? 'btn-primary' : 'btn-secondary'" @click="remind = !remind" :aria-pressed="remind">
                                <x-icon name="bell" class="size-4"/> <span x-text="remind ? 'Xatırlatma əlavə olunacaq' : 'Xatırlatma əlavə et'"></span>
                            </button>
                        </div>
                        <div x-show="plan === 'today'" class="rounded-xl border border-brand/25 bg-brand-soft/30 p-4">
                            <h3 class="text-sm font-semibold mb-3">Köçürmə şərtləri</h3>
                            <x-field label="İstinad / ödəniş tapşırığı №" name="reference" class="mb-4 max-w-xs"><input name="reference" class="input font-mono"></x-field>
                            @include('deals._logistics-terms')
                            <p class="text-xs text-muted mt-3" x-show="!terms">Ödəniş şəklini seçin. Aktı ödənişsiz saxlamaq üçün «Başqa tarixdə» seçin.</p>
                        </div>
                    </div>
                </div>
                <footer class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line">
                    <button type="button" class="btn btn-secondary" @click="open = false">Bağla</button>
                    <button class="btn btn-primary" :disabled="!canSubmit()"><x-icon name="check" class="size-4"/> <span x-text="plan === 'today' ? 'Aktı əlavə et və ödə' : 'Aktı əlavə et'"></span></button>
                </footer>
            </form>
        </div>
        </template>
    </div>
@endcan
