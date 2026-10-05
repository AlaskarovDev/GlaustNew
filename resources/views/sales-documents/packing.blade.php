{{-- Packing List (the PL sheet): header as on the proforma, then pallets — each with its colli / size, its products and weights. --}}
@php
    $canEdit = auth()->user()->can('projects.update');
    $palletsData = collect(old('pallets', $doc->pallets()))->map(fn ($p) => [
        'title' => (string) ($p['title'] ?? ''), 'packing' => (string) ($p['packing'] ?? ''), 'weight' => isset($p['weight']) && $p['weight'] !== null ? (string) (float) $p['weight'] : '',
        'items' => collect($p['items'] ?? [])->map(fn ($i) => [
            'code' => (string) ($i['code'] ?? ''), 'description' => (string) ($i['description'] ?? ''), 'package' => (string) ($i['package'] ?? ''),
            'quantity' => isset($i['quantity']) && $i['quantity'] !== null ? (string) (float) $i['quantity'] : '', 'qty_unit' => (string) ($i['qty_unit'] ?? ''),
            'total' => isset($i['total']) && $i['total'] !== null ? (string) (float) $i['total'] : '', 'total_unit' => (string) ($i['total_unit'] ?? ''),
            'weight' => isset($i['weight']) && $i['weight'] !== null ? (string) (float) $i['weight'] : '',
        ])->values(),
    ])->values();
    // what the proforma sells, to check the pallets add up to it
    $expected = collect($proforma?->lines ?? [])->map(fn ($l) => ['description' => (string) $l['description'], 'quantity' => (float) $l['quantity'], 'uom' => (string) ($l['uom'] ?? '')])->values();
@endphp
<x-layouts.app :title="'Packing List '.$doc->number" wide>
    <x-page-header :title="'Packing List № '.$doc->number" :back="route('deals.show', [$doc->deal, 'tab' => 'invoices'])"
                   :subtitle="__('Qablaşdırma siyahısı').' · '.($doc->counterparty?->name ?? '').' · Trade '.$doc->deal?->code">
        <x-slot:actions>
            @if($proforma)<a href="{{ route('sales-documents.show', $proforma) }}" class="btn btn-secondary"><x-icon name="contract" class="size-4"/> Proforma</a>@endif
            <a href="{{ route('sales-documents.pdf', [$doc, 'inline' => 1]) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="eye" class="size-4"/> {{ __('PDF-ə bax') }}</a>
            <a href="{{ route('sales-documents.pdf', $doc) }}" class="btn btn-primary"><x-icon name="file-pdf" class="size-4"/> {{ __('PDF yüklə') }}</a>
        </x-slot:actions>
    </x-page-header>

    <div class="rounded-xl border border-brand/25 bg-brand-soft/50 px-4 py-3 mb-4 flex flex-wrap items-start gap-3 text-sm">
        <x-icon name="pencil" class="size-4 text-brand-ink mt-0.5 shrink-0"/>
        <div class="flex-1 min-w-[220px]">
            <b class="text-brand-ink">{{ __('Redaktə edilə bilən sənəd.') }}</b>
            {{ __('Proforma ilə birlikdə avtomatik yaradılıb: məhsullar 1-ci paletdədir. Paletləri, colli/ölçüləri, miqdarları və çəkiləri daxil edin — oxşar paletləri «Paleti kopyala» ilə tez əlavə edin. Faktura kilidlənəndən sonra da dəyişdirilə bilər.') }}
        </div>
        <span class="text-xs text-muted">{{ __('Son dəyişiklik:') }} {{ azdate($doc->updated_at, true) }}{{ $doc->editor ? ' · '.$doc->editor->name : '' }}</span>
    </div>

    <form method="POST" action="{{ route('sales-documents.update', $doc) }}" class="space-y-6" x-data="{
            pallets: @js($palletsData),
            expected: @js($expected),
            num(v) { const n = parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')); return isNaN(n) ? 0 : n; },
            fmt: (v) => glaustFmt.fmt(v, 2),
            blankItem() { return { code: '', description: '', package: '', quantity: '', qty_unit: 'stck', total: '', total_unit: 'kg', weight: '' }; },
            addPallet() { this.pallets.push({ title: 'Pallet №' + (this.pallets.length + 1), packing: '', weight: '', items: [this.blankItem()] }); },
            copyPallet(i) { const c = JSON.parse(JSON.stringify(this.pallets[i])); c.title = 'Pallet №' + (this.pallets.length + 1); this.pallets.splice(this.pallets.length, 0, c); },
            removePallet(i) { if (this.pallets.length > 1) this.pallets.splice(i, 1); },
            addItem(p) { p.items.push(this.blankItem()); },
            removeItem(p, j) { if (p.items.length > 1) p.items.splice(j, 1); },
            // Total = quantity × package size when the package is like «200 kg»
            fillTotal(it) { const m = String(it.package).match(/^\s*([\d.,]+)\s*([a-zA-Zа-яА-Я²]+)?/); if (m && it.quantity !== '') { it.total = String(Math.round(this.num(it.quantity) * this.num(m[1]) * 1000) / 1000); if (m[2]) it.total_unit = m[2]; } },
            weight() { return Math.round(this.pallets.reduce((s, p) => s + this.num(p.weight), 0) * 100) / 100; },
            packed() { return Math.round(this.pallets.reduce((s, p) => s + p.items.reduce((t, i) => t + this.num(i.weight), 0), 0) * 100) / 100; },
            packedTotal(desc) { const d = desc.trim().toLowerCase(); return Math.round(this.pallets.reduce((s, p) => s + p.items.filter((i) => i.description.trim().toLowerCase() === d).reduce((t, i) => t + this.num(i.total), 0), 0) * 1000) / 1000; },
         }">
        @csrf @method('PUT')
        <fieldset @disabled(! $canEdit) class="space-y-6 min-w-0">
            <div class="grid lg:grid-cols-2 gap-6">
                <section class="card p-5 space-y-4">
                    <h2 class="text-sm font-semibold">{{ __('Rekvizitlər') }}</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <x-field label="Inv. Number" name="number" required><input name="number" value="{{ old('number', $doc->number) }}" class="input font-mono" required></x-field>
                        <x-field label="Inv. Date" name="doc_date" required><input type="date" name="doc_date" value="{{ old('doc_date', $doc->doc_date->toDateString()) }}" class="input" required></x-field>
                        <x-field label="Contract N" name="contract_number"><input name="contract_number" value="{{ old('contract_number', $doc->contract_number) }}" class="input font-mono"></x-field>
                        <x-field label="Cont. Date" name="contract_date"><input name="contract_date" value="{{ old('contract_date', $doc->contract_date) }}" placeholder="09.02.2023" class="input font-mono"></x-field>
                    </div>
                    <x-field :label="__('Başlıq (şirkətin adı)')" name="heading"><input name="heading" value="{{ old('heading', $doc->heading) }}" class="input"></x-field>
                </section>
                <section class="card p-5 grid sm:grid-cols-2 gap-4">
                    <x-field label="SELLER" name="seller_block"><textarea name="seller_block" rows="6" class="input text-sm">{{ old('seller_block', $doc->seller_block) }}</textarea></x-field>
                    <x-field label="CUSTOMER" name="customer_block"><textarea name="customer_block" rows="6" class="input text-sm">{{ old('customer_block', $doc->customer_block) }}</textarea></x-field>
                </section>
            </div>

            @if($errors->any())
                <div class="rounded-lg bg-danger-soft/60 text-danger text-sm px-3 py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
            @endif

            {{-- Pallets --}}
            <div class="space-y-4">
                <template x-for="(p, i) in pallets" :key="i">
                    <section class="card overflow-hidden">
                        <header class="flex flex-wrap items-end gap-3 px-4 py-3 border-b border-line bg-surface-2/50">
                            <label class="block w-36"><span class="text-[11px] text-muted">{{ __('Palet') }}</span>
                                <input :name="`pallets[${i}][title]`" x-model="p.title" class="input !h-9 font-semibold" required></label>
                            <label class="block flex-1 min-w-[200px]"><span class="text-[11px] text-muted">{{ __('Colli və ölçü') }}</span>
                                <input :name="`pallets[${i}][packing]`" x-model="p.packing" class="input !h-9 font-mono" placeholder="4 colli  115 x 115 x 105"></label>
                            <label class="block w-36"><span class="text-[11px] text-muted">{{ __('Paletlə çəki, kg') }}</span>
                                <input :name="`pallets[${i}][weight]`" x-model="p.weight" inputmode="decimal" class="input !h-9 font-mono text-right"></label>
                            <div class="flex gap-1 ml-auto">
                                <button type="button" class="btn btn-ghost btn-sm" @click="copyPallet(i)"><x-icon name="layers" class="size-4"/> {{ __('Paleti kopyala') }}</button>
                                <button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" @click="removePallet(i)" :disabled="pallets.length === 1" aria-label="{{ __('Paleti sil') }}"><x-icon name="trash" class="size-4"/></button>
                            </div>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="table-g text-sm">
                                <thead><tr>
                                    <th class="w-8">No.</th><th class="w-32">{{ __('Kod') }}</th><th>Description</th><th class="w-28">{{ __('Qablaşdırma') }}</th>
                                    <th class="w-36">Quantity</th><th class="w-36">Total</th><th class="w-28">{{ __('Qablaşdırma ilə çəki') }}</th><th class="w-10"></th>
                                </tr></thead>
                                <tbody>
                                <template x-for="(it, j) in p.items" :key="j">
                                    <tr>
                                        <td class="text-muted font-mono" x-text="j + 1"></td>
                                        <td><input :name="`pallets[${i}][items][${j}][code]`" x-model="it.code" class="input !h-8 font-mono text-xs" placeholder="510.1637.03"></td>
                                        <td><input :name="`pallets[${i}][items][${j}][description]`" x-model="it.description" class="input !h-8" required></td>
                                        <td><input :name="`pallets[${i}][items][${j}][package]`" x-model="it.package" @change="fillTotal(it)" class="input !h-8 font-mono text-xs" placeholder="200 kg"></td>
                                        <td><div class="flex gap-1"><input :name="`pallets[${i}][items][${j}][quantity]`" x-model="it.quantity" @change="fillTotal(it)" inputmode="decimal" class="input !h-8 font-mono text-right">
                                            <input :name="`pallets[${i}][items][${j}][qty_unit]`" x-model="it.qty_unit" class="input !h-8 !w-16 text-xs"></div></td>
                                        <td><div class="flex gap-1"><input :name="`pallets[${i}][items][${j}][total]`" x-model="it.total" inputmode="decimal" class="input !h-8 font-mono text-right">
                                            <input :name="`pallets[${i}][items][${j}][total_unit]`" x-model="it.total_unit" class="input !h-8 !w-14 text-xs"></div></td>
                                        <td><input :name="`pallets[${i}][items][${j}][weight]`" x-model="it.weight" inputmode="decimal" class="input !h-8 font-mono text-right"></td>
                                        <td><button type="button" class="btn btn-ghost btn-icon btn-sm text-danger" @click="removeItem(p, j)" :disabled="p.items.length === 1" aria-label="{{ __('Sətri sil') }}"><x-icon name="x" class="size-4"/></button></td>
                                    </tr>
                                </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-4 py-2 border-t border-line"><button type="button" class="btn btn-ghost btn-sm" @click="addItem(p)"><x-icon name="plus" class="size-4"/> {{ __('Məhsul əlavə et') }}</button></div>
                    </section>
                </template>
                <button type="button" class="btn btn-secondary" @click="addPallet()"><x-icon name="plus" class="size-4"/> {{ __('Palet əlavə et') }}</button>
            </div>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
                {{-- Check against the proforma --}}
                <section class="card p-5">
                    <h2 class="text-sm font-semibold mb-1">{{ __('Proforma ilə yoxlama') }}</h2>
                    <p class="text-xs text-muted mb-3">{{ __('Hər məhsul üzrə paletlərdəki «Total» cəmi proformadakı miqdarla müqayisə olunur (təsvir eyni olmalıdır).') }}</p>
                    <table class="table-g text-sm">
                        <thead><tr><th>{{ __('Məhsul') }}</th><th class="!text-right">{{ __('Proformada') }}</th><th class="!text-right">{{ __('Paletlərdə') }}</th><th class="!text-right">{{ __('Fərq') }}</th></tr></thead>
                        <tbody>
                        <template x-for="e in expected" :key="e.description">
                            <tr>
                                <td x-text="e.description"></td>
                                <td class="num" x-text="fmt(e.quantity) + ' ' + e.uom"></td>
                                <td class="num" x-text="fmt(packedTotal(e.description))"></td>
                                <td class="num font-medium" :class="Math.abs(packedTotal(e.description) - e.quantity) < 0.001 ? 'text-success' : 'text-danger'"
                                    x-text="Math.abs(packedTotal(e.description) - e.quantity) < 0.001 ? '✓' : fmt(packedTotal(e.description) - e.quantity)"></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                </section>
                <section class="card p-5 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-muted">{{ __('Paletlər') }}</span><span class="font-mono font-semibold" x-text="pallets.length"></span></div>
                    <div class="flex justify-between"><span class="text-muted">{{ __('Qablaşdırma ilə çəki') }}</span><span class="font-mono" x-text="fmt(packed()) + ' kg'"></span></div>
                    <div class="flex justify-between border-t border-line pt-2"><span class="font-semibold">{{ __('Paletlə ümumi çəki') }}</span><span class="font-mono font-semibold" x-text="fmt(weight()) + ' kg'"></span></div>
                    <x-field :label="__('Qeyd')" name="notes" class="pt-2"><textarea name="notes" rows="2" class="input text-sm">{{ old('notes', $doc->notes) }}</textarea></x-field>
                    @if($canEdit)<button class="btn btn-primary w-full"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>@endif
                </section>
            </div>
        </fieldset>
    </form>

    @include('partials.history', ['history' => $history])
</x-layouts.app>
