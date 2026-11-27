{{-- The imported lines corrected by hand: the seller's own figures (under the freight / discount when the invoice has them).
     Total follows Quantity × Unit Price until it is typed; saving works the totals, logistics and commission again. --}}
@php
    $hasTerms = $invoice->freight_amount !== null || $invoice->discount_percent !== null;
    $failed = collect($errors->keys())->contains(fn ($k) => $k === 'rows' || str_starts_with($k, 'rows.'));
    $rowsData = old('rows') !== null
        ? collect(old('rows'))->map(fn ($r, $i) => array_map(fn ($v) => (string) $v, array_intersect_key((array) $r, array_flip(['id', 'product_code', 'description', 'hs_code', 'quantity', 'uom', 'unit_price', 'total']))) + ['key' => 'o'.$i, 'touched' => true])->values()
        : $invoice->items->map(fn ($it) => [
            'key' => 'i'.$it->id, 'id' => (string) $it->id,
            'product_code' => (string) $it->product_code, 'description' => (string) $it->description, 'hs_code' => (string) $it->hs_code,
            'quantity' => rtrim(rtrim(number_format((float) $it->quantity, 3, ',', ''), '0'), ','), 'uom' => (string) $it->uom,
            'unit_price' => rtrim(rtrim(number_format((float) ($it->base_unit_price ?? $it->unit_price), 4, ',', ''), '0'), ','),
            'total' => number_format((float) ($it->base_total ?? $it->total), 2, ',', ''), 'touched' => false,
        ])->values();
@endphp
<div x-data="{
        open: {{ $failed ? 'true' : 'false' }},
        rows: @js($rowsData), seq: 0,
        add() { const last = this.rows[this.rows.length - 1]; this.rows.push({ key: 'n' + (++this.seq), id: '', product_code: '', description: '', hs_code: last?.hs_code ?? '', quantity: '1', uom: last?.uom ?? '', unit_price: '', total: '', touched: false }); this.$nextTick(() => this.$root.querySelector('tbody tr:last-child textarea')?.focus()); },
        remove(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
        num(v) { const n = parseFloat(String(v ?? '').replace(/[\s ]/g, '').replace(',', '.')); return isNaN(n) ? 0 : n; },
        calc(r) { if (!r.touched) r.total = (Math.round(this.num(r.quantity) * this.num(r.unit_price) * 100) / 100).toFixed(2).replace('.', ','); },
        sum() { return this.rows.reduce((s, r) => s + this.num(r.total), 0); },
        fmt: (v) => glaustFmt.fmt(v, 2),
     }" class="mb-3">
    <div class="flex items-center justify-end gap-2">
        <button type="button" class="btn btn-secondary btn-sm" @click="open = !open"><x-icon name="pencil" class="size-3.5"/> <span x-text="open ? {{ \Illuminate\Support\Js::from(__('Bağla')) }} : {{ \Illuminate\Support\Js::from(__('Sətirləri redaktə et')) }}"></span></button>
    </div>
    <form method="POST" action="{{ route('invoices.items.update', $invoice) }}" x-show="open" x-collapse @unless($failed) x-cloak @endunless class="card mt-3 overflow-hidden">
        @csrf @method('PUT')
        <header class="px-5 py-3 border-b border-line">
            <h2 class="text-sm font-semibold">{{ __('Məhsul sətirlərinin redaktəsi') }}</h2>
            <p class="text-xs text-muted">{{ $hasTerms ? __('Satıcının ilkin rəqəmlərini dəyişirsiniz — navlun və endirim yenidən bölünəcək.') : __('Total, Quantity × Unit Price ilə hesablanır; əl ilə də yazmaq olar.') }} {{ __('Sətir əlavə edib silə bilərsiniz; saxlananda N 1-dən yenidən nömrələnir, logistika və komissiya yenidən hesablanır.') }}</p>
        </header>
        @if($failed)<p class="px-5 pt-3 field-error">{{ collect($errors->getMessages())->filter(fn ($v, $k) => str_starts_with($k, 'rows'))->flatten()->first() }}</p>@endif
        <div class="overflow-x-auto">
            <table class="table-g text-[13px]">
                <thead lang="en"><tr>
                    <th>N</th><th>Product Code</th><th>Description</th><th>HS Code</th><th class="!text-right">Quantity</th><th>UOM</th><th class="!text-right">Unit Price</th><th class="!text-right">Total/{{ $invoice->currency }}</th><th></th>
                </tr></thead>
                <tbody>
                <template x-for="(r, i) in rows" :key="r.key">
                    <tr :class="!r.id && 'bg-brand-soft/30'">
                        <td class="font-mono"><span x-text="i + 1"></span><input type="hidden" :name="'rows[' + i + '][id]'" :value="r.id"></td>
                        <td><input :name="'rows[' + i + '][product_code]'" x-model="r.product_code" class="input font-mono min-w-[130px]" maxlength="64"></td>
                        <td><textarea :name="'rows[' + i + '][description]'" x-model="r.description" rows="2" class="input min-w-[260px]" maxlength="255" required></textarea></td>
                        <td><input :name="'rows[' + i + '][hs_code]'" x-model="r.hs_code" class="input font-mono min-w-[110px]" maxlength="20"></td>
                        <td><input :name="'rows[' + i + '][quantity]'" x-model="r.quantity" @input="calc(r)" inputmode="decimal" class="input font-mono text-right min-w-[90px]" required></td>
                        <td><input :name="'rows[' + i + '][uom]'" x-model="r.uom" class="input min-w-[70px]" maxlength="16"></td>
                        <td><input :name="'rows[' + i + '][unit_price]'" x-model="r.unit_price" @input="calc(r)" inputmode="decimal" class="input font-mono text-right min-w-[110px]" required></td>
                        <td><input :name="'rows[' + i + '][total]'" x-model="r.total" @input="r.touched = true" inputmode="decimal" class="input font-mono text-right min-w-[120px]"></td>
                        <td><button type="button" class="btn btn-ghost btn-icon btn-sm text-danger hover:!bg-danger-soft" @click="remove(i)" :disabled="rows.length === 1" aria-label="{{ __('Sətri sil') }}" title="{{ __('Sətri sil') }}"><x-icon name="trash" class="size-4"/></button></td>
                    </tr>
                </template>
                </tbody>
                <tfoot><tr class="bg-surface-2 font-semibold">
                    <td class="px-4 py-3" colspan="7"><button type="button" class="btn btn-secondary btn-sm" @click="add()"><x-icon name="plus" class="size-3.5"/> {{ __('Sətir əlavə et') }}</button>
                        <span class="ml-3 font-normal text-xs text-muted" x-text="rows.length + ' {{ __('sətir') }}'"></span></td>
                    <td class="px-4 py-3 text-right font-mono" x-text="fmt(sum())"></td><td></td>
                </tr></tfoot>
            </table>
        </div>
        <footer class="px-5 py-3 border-t border-line flex justify-end gap-2">
            <button type="button" class="btn btn-ghost" @click="open = false">{{ __('Ləğv et') }}</button>
            <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
        </footer>
    </form>
</div>
