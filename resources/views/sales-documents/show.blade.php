@php
    $pf = $doc->isProforma();
    $canEdit = auth()->user()->can('projects.update') && ! $doc->isLocked();
    $linesData = collect(old('lines', $doc->lines))->map(fn ($l) => [
        'description' => (string) ($l['description'] ?? ''), 'hs_code' => (string) ($l['hs_code'] ?? ''), 'uom' => (string) ($l['uom'] ?? ''),
        'quantity' => isset($l['quantity']) && $l['quantity'] !== '' ? (string) (is_numeric($l['quantity']) ? (float) $l['quantity'] : $l['quantity']) : '',
        'unit_price' => isset($l['unit_price']) && $l['unit_price'] !== '' ? (string) (is_numeric($l['unit_price']) ? (float) $l['unit_price'] : $l['unit_price']) : '',
    ])->values();
    $proformaTotal = ! $pf && $sibling?->isProforma() ? $sibling->grandTotal() : null;
@endphp
<x-layouts.app :title="$doc->title().' '.$doc->number" wide>
    <x-page-header :title="$doc->title().' № '.$doc->number" :back="route('deals.show', [$doc->deal, 'tab' => 'invoices'])"
                   :subtitle="$doc->label().' · '.($doc->counterparty?->name ?? '').' · Trade '.$doc->deal?->code">
        <x-slot:actions>
            @if($sibling)
                <a href="{{ route('sales-documents.show', $sibling) }}" class="btn btn-secondary"><x-icon name="contract" class="size-4"/> {{ $sibling->title() }}</a>
            @endif
            <a href="{{ route('sales-documents.pdf', [$doc, 'inline' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="eye" class="size-4"/> {{ __('PDF-ə bax') }}</a>
            <a href="{{ route('sales-documents.pdf', $doc) }}" class="btn btn-primary"><x-icon name="file-pdf" class="size-4"/> {{ __('PDF yüklə') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if($doc->isLocked())
        <div class="lock-banner mb-4" role="alert">
            <span class="lock-pulse" aria-hidden="true"></span>
            <x-icon name="lock" class="size-5 shrink-0"/>
            <div class="flex-1 min-w-[220px]">
                <div class="font-semibold">{{ $doc->kind === 'commercial' ? __('Kommersiya fakturası — təsdiqdən sonra yaradılıb, dəyişdirilmir') : __('Sənəddə düzəliş əməliyyatlarına icazə dayandırılıb') }}</div>
                <div class="text-xs opacity-80">{{ $doc->sourceInvoice?->isApproved() ? __('Faktura ').$doc->sourceInvoice->number.__(' təsdiqlənib.') : __('Faktura ').$doc->sourceInvoice?->number.__(' təsdiqdədir.') }} {{ __('PDF yükləmək mümkündür.') }}</div>
            </div>
        </div>
    @else
    <div class="rounded-xl border border-brand/25 bg-brand-soft/50 px-4 py-3 mb-4 flex flex-wrap items-start gap-3 text-sm">
        <x-icon name="pencil" class="size-4 text-brand-ink mt-0.5 shrink-0"/>
        <div class="flex-1 min-w-[220px]">
            <b class="text-brand-ink">{{ __('Redaktə edilə bilən sənəd.') }}</b>
            {{ __('Hesablamadan avtomatik yaradılıb; bütün sahələr və sətirlər dəyişdirilə bilər. PDF hər dəfə son yadda saxlanmış vəziyyətdən yaradılır, dəyişikliklər aşağıdakı tarixçədə qalır.') }}
        </div>
        <span class="text-xs text-muted">{{ __('Son dəyişiklik:') }} {{ azdate($doc->updated_at, true) }}{{ $doc->editor ? ' · '.$doc->editor->name : '' }}</span>
    </div>
    @endif

    @if($stale && $canEdit)
        <div class="rounded-xl border border-saffron/40 bg-saffron-soft/50 px-4 py-3 mb-4 flex flex-wrap items-center gap-3 text-sm" role="status">
            <x-icon name="alert" class="size-4 text-saffron shrink-0"/>
            <span class="flex-1 min-w-[220px]">{{ __('Sətirlər faktura') }} <b>{{ $doc->sourceInvoice?->number }}</b>{{ __('-in hazırkı hesablamasından fərqlənir (əl ilə dəyişilib və ya hesablama yenilənib).') }}</span>
            <form method="POST" action="{{ route('sales-documents.refresh', $doc) }}" data-confirm="{{ __('Sətirlər hesablamadan yenidən yazılsın? Sətirlərdə əl ilə edilən dəyişikliklər itəcək (rekvizitlər qalır).') }}" data-confirm-action="{{ __('Yenilə') }}">
                @csrf
                <button class="btn btn-secondary btn-sm"><x-icon name="refresh" class="size-4"/> {{ __('Hesablamadan yenilə') }}</button>
            </form>
        </div>
    @endif

    <form method="POST" action="{{ route('sales-documents.update', $doc) }}" class="space-y-6" x-data="{
            lines: @js($linesData),
            freight: @js((string) old('freight', (float) $doc->freight ?: '')),
            insurance: @js((string) old('insurance', (float) $doc->insurance ?: '')),
            proformaTotal: @js($proformaTotal),
            num(v) { const n = parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')); return isNaN(n) ? 0 : n; },
            lineTotal(l) { return Math.round(this.num(l.quantity) * this.num(l.unit_price) * 100) / 100; },
            subtotal() { return Math.round(this.lines.reduce((s, l) => s + this.lineTotal(l), 0) * 100) / 100; },
            total() { return Math.round((this.subtotal() + this.num(this.freight) + this.num(this.insurance)) * 100) / 100; },
            diff() { return this.proformaTotal === null ? null : Math.round((this.total() - this.proformaTotal) * 100) / 100; },
            add() { this.lines.push({ description: '', hs_code: '', uom: this.lines.at(-1)?.uom || '', quantity: '', unit_price: '' }); this.$nextTick(() => this.$root.querySelector('tbody tr:last-child textarea')?.focus()); },
            remove(i) { if (this.lines.length > 1) this.lines.splice(i, 1); },
            move(i, d) { const j = i + d; if (j < 0 || j >= this.lines.length) return; [this.lines[i], this.lines[j]] = [this.lines[j], this.lines[i]]; },
            fmt: (v) => glaustFmt.fmt(v, 2),
         }">
        @csrf @method('PUT')
        <fieldset @disabled(! $canEdit) class="space-y-6 min-w-0">
            <div class="grid lg:grid-cols-2 gap-6">
                <section class="card p-5 space-y-4">
                    <h2 class="text-sm font-semibold">{{ __('Rekvizitlər') }}</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-field :label="$pf ? 'Proforma Inv. Number' : 'Спецификация №'" name="number" required>
                            <input name="number" value="{{ old('number', $doc->number) }}" class="input font-mono @error('number') is-invalid @enderror" required>
                        </x-field>
                        <x-field :label="$pf ? 'Proforma Inv. Date' : 'Дата (от)'" name="doc_date" required>
                            <input type="date" name="doc_date" value="{{ old('doc_date', $doc->doc_date->toDateString()) }}" class="input @error('doc_date') is-invalid @enderror" required>
                        </x-field>
                        <x-field :label="$pf ? 'Contract N' : 'к Контракту №'" name="contract_number">
                            <input name="contract_number" value="{{ old('contract_number', $doc->contract_number) }}" class="input font-mono">
                        </x-field>
                        <x-field :label="$pf ? 'Cont. Date' : 'Дата контракта'" name="contract_date">
                            <input name="contract_date" value="{{ old('contract_date', $doc->contract_date) }}" placeholder="09.02.2023" class="input font-mono">
                        </x-field>
                    </div>
                    @if($pf)
                        <x-field :label="__('Başlıqdakı şirkət adı')" name="heading" :hint="__('PDF-in yuxarı sol küncündə böyük hərflərlə')">
                            <input name="heading" value="{{ old('heading', $doc->heading) }}" class="input">
                        </x-field>
                    @endif
                </section>

                <section class="card p-5 space-y-4">
                    @if($pf)
                        <h2 class="text-sm font-semibold">{{ __('Tərəflər') }}</h2>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <x-field label="SELLER" name="seller_block" :hint="__('Hər sətir PDF-də ayrıca sətir olur')">
                                <textarea name="seller_block" rows="6" class="input text-[13px]">{{ old('seller_block', $doc->seller_block) }}</textarea>
                            </x-field>
                            <x-field label="CUSTOMER" name="customer_block">
                                <textarea name="customer_block" rows="6" class="input text-[13px]">{{ old('customer_block', $doc->customer_block) }}</textarea>
                            </x-field>
                        </div>
                    @else
                        <h2 class="text-sm font-semibold">{{ __('İmzalar') }}</h2>
                        <x-field label="От ПРОДАВЦА" name="seller_signatory">
                            <input name="seller_signatory" value="{{ old('seller_signatory', $doc->seller_signatory) }}" placeholder="Генеральный директор …" class="input">
                        </x-field>
                        <x-field label="От ПОКУПАТЕЛЯ" name="buyer_signatory">
                            <input name="buyer_signatory" value="{{ old('buyer_signatory', $doc->buyer_signatory) }}" placeholder="Генеральный директор …" class="input">
                        </x-field>
                    @endif
                </section>
            </div>

            <section class="card overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
                    <div>
                        <h2 class="text-sm font-semibold">{{ __('Sətirlər') }} <span class="font-mono text-muted font-normal" x-text="lines.length"></span></h2>
                        <p class="text-xs text-muted">{{ __('Məbləğ = miqdar × qiymət, qəpiyə qədər. Sətir əlavə etmək, silmək və yerini dəyişmək olar.') }}</p>
                    </div>
                    @if($canEdit)<button type="button" class="btn btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="size-4"/> {{ __('Sətir əlavə et') }}</button>@endif
                </header>
                @error('lines')<p class="field-error px-5 pt-3">{{ $message }}</p>@enderror
                @foreach($errors->get('lines.*') as $msgs)<p class="field-error px-5 pt-1">{{ $msgs[0] }}</p>@endforeach
                <div class="overflow-x-auto">
                    <table class="table-g text-[13px]">
                        <thead lang="{{ $pf ? 'en' : 'ru' }}">
                        <tr>
                            <th class="w-12">{{ $pf ? 'Item #' : 'Поз.' }}</th>
                            <th class="min-w-[240px]">{{ $pf ? 'Description' : 'Наименование товара' }}</th>
                            @if($pf)<th class="w-32">{{ __('Custom Code') }}</th>@endif
                            <th class="w-28 !text-right">{{ $pf ? 'QTY' : 'Количество' }}</th>
                            <th class="w-24">{{ $pf ? 'UOM' : 'Ед. изм.' }}</th>
                            <th class="w-32 !text-right">{{ $pf ? 'Unit Price '.$doc->currency : 'Цена, руб.' }}</th>
                            <th class="w-36 !text-right">{{ $pf ? 'Total Price '.$doc->currency : 'Сумма, руб.' }}</th>
                            <th class="w-20"><span class="sr-only">{{ __('Əməliyyat') }}</span></th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="(l, i) in lines" :key="i">
                            <tr class="align-top">
                                <td class="font-mono pt-4" x-text="i + 1"></td>
                                <td><textarea :name="`lines[${i}][description]`" x-model="l.description" rows="1" class="input !h-auto min-h-9 py-1.5 text-[13px] resize-y" :aria-label="{{ \Illuminate\Support\Js::from(__('Təsvir, sətir ')) }} + (i + 1)"></textarea></td>
                                @if($pf)<td><input :name="`lines[${i}][hs_code]`" x-model="l.hs_code" class="input !h-9 font-mono" :aria-label="{{ \Illuminate\Support\Js::from(__('Gömrük kodu, sətir ')) }} + (i + 1)"></td>@else<input type="hidden" :name="`lines[${i}][hs_code]`" :value="l.hs_code">@endif
                                <td><input :name="`lines[${i}][quantity]`" x-model="l.quantity" inputmode="decimal" class="input !h-9 font-mono text-right" :aria-label="{{ \Illuminate\Support\Js::from(__('Miqdar, sətir ')) }} + (i + 1)"></td>
                                <td><input :name="`lines[${i}][uom]`" x-model="l.uom" class="input !h-9" :aria-label="{{ \Illuminate\Support\Js::from(__('Ölçü vahidi, sətir ')) }} + (i + 1)"></td>
                                <td><input :name="`lines[${i}][unit_price]`" x-model="l.unit_price" inputmode="decimal" class="input !h-9 font-mono text-right" :aria-label="{{ \Illuminate\Support\Js::from(__('Qiymət, sətir ')) }} + (i + 1)"></td>
                                <td class="num font-medium text-ink pt-4" x-text="fmt(lineTotal(l))"></td>
                                <td class="pt-2">
                                    @if($canEdit)
                                        <div class="flex items-center gap-0.5">
                                            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(i, -1)" :disabled="i === 0" aria-label="{{ __('Yuxarı') }}"><x-icon name="chevron-up" class="size-4"/></button>
                                            <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" @click="remove(i)" :disabled="lines.length === 1" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" class="size-4"/></button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        </template>
                        </tbody>
                        <tfoot>
                        <tr class="bg-surface-2">
                            <td colspan="{{ $pf ? 6 : 5 }}" class="px-4 py-3 text-right font-semibold">{{ $pf ? 'Subtotal ('.$doc->currency.')' : 'ИТОГО' }}</td>
                            <td class="px-4 py-3 text-right font-mono font-semibold" x-text="fmt(subtotal())"></td><td></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
                <section class="card p-5 space-y-4">
                    <h2 class="text-sm font-semibold">{{ $pf ? 'Special Notes, Terms of Sale' : 'Условия' }}</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-field :label="$pf ? 'Payment Terms' : 'Условия оплаты'" name="payment_terms">
                            <input name="payment_terms" value="{{ old('payment_terms', $doc->payment_terms) }}" class="input">
                        </x-field>
                        <x-field :label="$pf ? 'Delivery Terms' : 'Срок поставки'" name="delivery_terms">
                            <input name="delivery_terms" value="{{ old('delivery_terms', $doc->delivery_terms) }}" placeholder="{{ $pf ? 'DAP Moscow, 21 weeks (150 days)' : 'DAP 21 недель (150 календарных дней)' }}" class="input">
                        </x-field>
                    </div>
                    <x-field :label="__('Əlavə qeyd (PDF-də görünür)')" name="notes">
                        <textarea name="notes" rows="2" class="input">{{ old('notes', $doc->notes) }}</textarea>
                    </x-field>
                </section>

                <section class="card p-5 space-y-3">
                    <h2 class="text-sm font-semibold">{{ __('Cəm') }}</h2>
                    @if($pf)
                        <div class="grid grid-cols-2 gap-3">
                            <x-field :label="__('Freight')" name="freight"><input name="freight" x-model="freight" inputmode="decimal" placeholder="-" class="input font-mono text-right"></x-field>
                            <x-field :label="__('Insurance')" name="insurance"><input name="insurance" x-model="insurance" inputmode="decimal" placeholder="-" class="input font-mono text-right"></x-field>
                        </div>
                    @endif
                    <div class="flex items-baseline justify-between rounded-lg bg-surface-2 px-3 py-2.5">
                        <span class="text-sm text-muted">{{ $pf ? 'TOTAL ('.$doc->currency.')' : 'Итого' }}</span>
                        <span class="font-mono text-lg font-semibold" x-text="fmt(total())"></span>
                    </div>
                    <template x-if="diff() !== null">
                        <div class="text-xs rounded-lg px-3 py-2" :class="diff() === 0 ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'">
                            {{ __('Proforma cəmi:') }} <span class="font-mono" x-text="fmt(proformaTotal)"></span> ·
                            <span x-text="diff() === 0 ? 'eynidir' : {{ \Illuminate\Support\Js::from(__('fərq: ')) }} + (diff() > 0 ? '+' : '') + fmt(diff())"></span>
                        </div>
                    </template>
                    @unless($pf)
                        <p class="text-[11px] text-muted">{{ __('Sözlə:') }} {{ \App\Support\RuMoney::words($doc->grandTotal(), $doc->currency) }} <span class="text-faint">{{ __('(yadda saxladıqdan sonra yenilənir)') }}</span></p>
                    @endunless
                </section>
            </div>
        </fieldset>

        @if($canEdit)
            <div class="sticky bottom-4 z-20 flex justify-end">
                <div class="card !shadow-[var(--shadow-pop)] flex items-center gap-3 px-4 py-3">
                    <span class="text-sm text-muted hidden sm:inline">{{ __('Cəmi:') }} <span class="font-mono text-ink" x-text="fmt(total()) + ' {{ $doc->currency }}'"></span></span>
                    <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
                </div>
            </div>
        @endif
    </form>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start mt-6">
        @include('partials.history', ['history' => $history])
        <div class="space-y-3">
            @if($doc->sourceInvoice)
                <a href="{{ route('invoices.show', $doc->sourceInvoice) }}" class="card card-hover p-4 flex items-center gap-3 text-sm">
                    <x-icon name="sheet" class="size-5 text-success"/>
                    <span class="flex-1">{{ __('Hesablama: satıcının fakturası') }} <b class="font-mono">{{ $doc->sourceInvoice->number }}</b></span>
                    <x-icon name="chevron-right" class="size-4 text-muted"/>
                </a>
            @endif
            @if(auth()->user()->can('projects.delete') && ! $doc->isLocked())
                <x-delete-form :action="route('sales-documents.destroy', $doc)" :label="$doc->title().' sil'" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="$doc->title().' '.$doc->number.__(' silinəcək. Fakturadan yenidən yaratmaq olar.')"/>
            @endif
        </div>
    </div>
</x-layouts.app>
