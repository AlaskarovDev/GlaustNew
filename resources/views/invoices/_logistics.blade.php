{{-- Logistics cost of a seller's invoice: forecast/actual, one amount split by Total share, or per line in ONE currency. --}}
@php
    $currencies = config('glaust.currencies');
    $rowsData = $invoice->items->map(fn ($it) => [
        'id' => $it->id, 'n' => $it->line_no, 'description' => $it->description, 'quantity' => (float) $it->quantity, 'uom' => $it->uom,
        'total' => (float) $it->total,
        'amount' => old('items.'.$it->id, $invoice->logistics_method === 'per_item' && $it->logistics_original !== null ? (string) $it->logistics_original : ''),
        'currency' => old('item_currency.'.$it->id, $invoice->logistics_method === 'per_item' ? $invoice->logistics_currency : ''),
    ])->values();
    $grand = (float) $invoice->items->sum('total');
@endphp
<section class="card min-w-0" x-data="{
        method: @js(old('logistics_method', $invoice->logistics_method ?? 'total')),
        mode: @js(old('logistics_mode', $invoice->logistics_mode ?? 'forecast')),
        amount: @js((string) old('logistics_amount', $invoice->logistics_method === 'total' ? $invoice->logistics_amount : '')),
        currency: @js(old('logistics_currency', $invoice->logistics_method === 'total' ? $invoice->logistics_currency : $invoice->currency)),
        grand: {{ $grand }},
        rows: @js($rowsData),
        modal: false,
        editing: {{ $invoice->hasLogistics() ? 'false' : 'true' }},
        lockedCurrency() { const r = this.rows.find(r => r.currency); return r ? r.currency : null; },
        pickCurrency(row, value, el) {
            const locked = this.rows.find(r => r !== row && r.currency && r.currency !== value);
            if (value && locked) {
                el.value = row.currency || '';
                toast('warning', 'Siz daha öncəki məhsulda ' + locked.currency + ' valyutasını seçmisiniz. Bütün məhsullar üzrə xərc eyni valyutada olmalıdır.');
                return;
            }
            row.currency = value;
        },
        fillCurrency() { const c = this.lockedCurrency(); if (c) this.rows.forEach(r => r.currency = c); },
        num(v) { return parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')) || 0; },
        share(row) { return this.grand ? row.total * this.num(this.amount) / this.grand : 0; },
        perItemTotal() { return this.rows.reduce((s, r) => s + this.num(r.amount), 0); },
        complete() { return this.rows.every(r => r.amount !== '' && r.amount !== null && r.currency); },
        fmt: (v) => glaustFmt.fmt(v, 2),
     }">
    <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="truck" class="size-5 text-saffron"/> Logistika xərci</h2>
            <p class="text-xs text-muted">Məhsullar üzrə bölünür: sətrin Total/EUR payı × ümumi logistika (Excel-dəki =H*$I$cəm/$H$cəm düsturu)</p>
        </div>
        @if($invoice->hasLogistics())
            <div class="flex flex-wrap items-center gap-2">
                <span @class(['badge', 'badge-amber' => $invoice->logistics_mode === 'forecast', 'badge-green' => $invoice->logistics_mode === 'actual'])>{{ \App\Models\Invoice::LOGISTICS_MODES[$invoice->logistics_mode] }}</span>
                <span class="font-mono font-semibold">{{ money($invoice->logistics_amount, $invoice->logistics_currency) }}</span>
                @if($invoice->logistics_currency !== $invoice->currency)
                    <span class="text-sm text-muted font-mono">= {{ money($invoice->logistics_total, $invoice->currency) }}</span>
                @endif
                @can('projects.update')
                    <button type="button" class="btn btn-secondary btn-sm" @click="editing = !editing" x-text="editing ? 'Bağla' : 'Dəyiş'"></button>
                @endcan
            </div>
        @endif
    </header>

    @if($invoice->hasLogistics())
        <p class="px-5 pt-3 text-xs text-muted">
            {{ $invoice->logistics_method === 'total' ? 'Ümumi məbləğ Total/EUR payına görə bölünüb.' : 'Hər məhsul üzrə ayrıca daxil edilib.' }}
            @if($invoice->logistics_currency !== $invoice->currency)
                {{ $invoice->logistics_currency }} → {{ $invoice->currency }}: CBAR {{ azdate($invoice->invoice_date) }}, 1 {{ $invoice->logistics_currency }} = {{ rate_fmt($invoice->logistics_rate) }} {{ $invoice->currency }}.
            @endif
            Son dəyişiklik: {{ azdate($invoice->logistics_updated_at, true) }}.
        </p>
    @endif

    @can('projects.update')
        <form method="POST" action="{{ route('invoices.logistics', $invoice) }}" x-show="editing" x-collapse {{ $invoice->hasLogistics() ? 'x-cloak' : '' }} class="p-5 space-y-5">
            @csrf
            <input type="hidden" name="logistics_method" :value="method">
            <div class="grid md:grid-cols-2 gap-5">
                <fieldset>
                    <legend class="field-label">Xərcin növü <span class="text-danger">*</span></legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(\App\Models\Invoice::LOGISTICS_MODES as $k => $l)
                            <label class="flex items-center justify-center gap-2 h-10 rounded-lg border text-sm font-medium cursor-pointer transition-colors"
                                   :class="mode === '{{ $k }}' ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line text-ink-2 hover:border-line-strong'">
                                <input type="radio" name="logistics_mode" value="{{ $k }}" x-model="mode" class="sr-only"> {{ $l }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset>
                    <legend class="field-label">Necə daxil edilsin? <span class="text-danger">*</span></legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center justify-center h-10 rounded-lg border text-sm font-medium cursor-pointer transition-colors" :class="method === 'total' ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line text-ink-2 hover:border-line-strong'">
                            <input type="radio" value="total" x-model="method" class="sr-only"> Ümumi məbləğ
                        </label>
                        <label class="flex items-center justify-center h-10 rounded-lg border text-sm font-medium cursor-pointer transition-colors" :class="method === 'per_item' ? 'border-brand bg-brand-soft text-brand-ink' : 'border-line text-ink-2 hover:border-line-strong'">
                            <input type="radio" value="per_item" x-model="method" class="sr-only"> Hər məhsul üzrə
                        </label>
                    </div>
                </fieldset>
            </div>

            {{-- One amount --}}
            <div x-show="method === 'total'" class="grid sm:grid-cols-[1fr_140px_auto] gap-3 items-end">
                <x-field label="Ümumi logistika xərci" name="logistics_amount">
                    <input name="logistics_amount" x-model="amount" :disabled="method !== 'total'" inputmode="decimal" placeholder="Məs: 10 200" class="input font-mono text-right @error('logistics_amount') is-invalid @enderror">
                </x-field>
                <x-field label="Valyuta" name="logistics_currency">
                    <select name="logistics_currency" x-model="currency" :disabled="method !== 'total'" class="input">
                        @foreach($currencies as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </x-field>
                <button class="btn btn-primary"><x-icon name="check" class="size-4"/> Bölüşdür və yadda saxla</button>
            </div>
            <p x-show="method === 'total' && currency !== @js($invoice->currency)" class="text-xs text-muted -mt-2">
                {{ $invoice->currency }}-a faktura tarixinin ({{ azdate($invoice->invoice_date) }}) CBAR məzənnələri ilə çevriləcək.
            </p>

            {{-- Per line: popup --}}
            <div x-show="method === 'per_item'" class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-secondary" @click="modal = true"><x-icon name="list" class="size-4"/> Məhsullar üzrə xərcləri daxil et ({{ $invoice->items->count() }})</button>
                <span class="text-sm text-muted" x-show="perItemTotal() > 0">Cəmi: <span class="font-mono text-ink" x-text="fmt(perItemTotal()) + ' ' + (lockedCurrency() || '')"></span></span>
                <button class="btn btn-primary" :disabled="!complete()"><x-icon name="check" class="size-4"/> Yadda saxla</button>
                <span class="text-xs text-saffron" x-show="!complete()">Bütün sətirlər üçün məbləğ və valyuta daxil edin.</span>
            </div>
            @error('item_currency')<p class="field-error">{{ $message }}</p>@enderror
            @foreach($errors->get('items.*') as $msgs)<p class="field-error">{{ $msgs[0] }}</p>@endforeach

            {{-- Modal (inside the form: its inputs are submitted) --}}
            <div x-cloak x-show="modal" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="lg-title" @keydown.escape.window="modal = false">
                <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="modal = false"></div>
                <div x-show="modal" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                     class="relative w-full max-w-4xl max-h-[90vh] card !shadow-[var(--shadow-pop)] flex flex-col" x-trap.noscroll="modal">
                    <header class="flex items-start justify-between gap-4 px-6 py-4 border-b border-line">
                        <div>
                            <h2 id="lg-title" class="text-lg font-semibold">Məhsullar üzrə logistika xərci</h2>
                            <p class="text-xs text-muted">Hər məhsul üçün məbləğ və valyuta. Bütün məhsullarda <b>eyni valyuta</b> olmalıdır.</p>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" @click="modal = false" aria-label="Bağla"><x-icon name="x" class="size-5"/></button>
                    </header>
                    <div class="overflow-y-auto">
                        <table class="table-g text-[13px]">
                            <thead lang="en"><tr><th>N</th><th>Description</th><th class="!text-right">Quantity</th><th class="!text-right">Total/{{ $invoice->currency }}</th><th class="!text-right w-40">Xərc</th><th class="w-28">Valyuta</th></tr></thead>
                            <tbody>
                            <template x-for="row in rows" :key="row.id">
                                <tr>
                                    <td class="font-mono" x-text="row.n"></td>
                                    <td class="min-w-[200px]" x-text="row.description"></td>
                                    <td class="num" x-text="fmt(row.quantity) + ' ' + (row.uom || '')"></td>
                                    <td class="num" x-text="fmt(row.total)"></td>
                                    <td><input :name="`items[${row.id}]`" x-model="row.amount" inputmode="decimal" class="input !h-9 font-mono text-right" :aria-label="'Xərc: ' + row.description"></td>
                                    <td>
                                        <select :name="`item_currency[${row.id}]`" class="input !h-9" :value="row.currency" @change="pickCurrency(row, $event.target.value, $event.target)" :aria-label="'Valyuta: ' + row.description">
                                            <option value="">—</option>
                                            @foreach($currencies as $c)<option value="{{ $c }}" :selected="row.currency === '{{ $c }}'">{{ $c }}</option>@endforeach
                                        </select>
                                    </td>
                                </tr>
                            </template>
                            </tbody>
                        </table>
                    </div>
                    <footer class="flex flex-wrap items-center gap-3 px-6 py-4 border-t border-line">
                        <span class="text-sm">Cəmi: <span class="font-mono font-semibold" x-text="fmt(perItemTotal()) + ' ' + (lockedCurrency() || '')"></span></span>
                        <button type="button" class="btn btn-ghost btn-sm" x-show="lockedCurrency()" @click="fillCurrency()">Hamısına <span x-text="lockedCurrency()"></span> seç</button>
                        <span class="ml-auto"></span>
                        <button type="button" class="btn btn-secondary" @click="modal = false">Bağla</button>
                        <button class="btn btn-primary" :disabled="!complete()"><x-icon name="check" class="size-4"/> Yadda saxla</button>
                    </footer>
                </div>
            </div>
        </form>
        @if($invoice->hasLogistics())
            <form method="POST" action="{{ route('invoices.logistics.clear', $invoice) }}" x-show="editing" x-cloak class="px-5 pb-5 -mt-2" data-confirm="Logistika xərci bütün sətirlərdən silinsin?" data-confirm-action="Sil">
                @csrf @method('DELETE')
                <button class="text-xs text-danger hover:underline">Logistika xərcini sil</button>
            </form>
        @endif
    @endcan
</section>
