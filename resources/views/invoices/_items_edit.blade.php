{{-- The imported lines corrected by hand: the seller's own figures (under the freight / discount when the invoice has them).
     Total follows Quantity × Unit Price until it is typed; saving works the totals, logistics and commission again. --}}
@php
    $hasTerms = $invoice->freight_amount !== null || $invoice->discount_percent !== null;
    $failed = $errors->has('items') || collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'items.'));
    $rowsData = $invoice->items->map(fn ($it) => [
        'id' => $it->id, 'line_no' => $it->line_no,
        'product_code' => (string) old("items.{$it->id}.product_code", $it->product_code),
        'description' => (string) old("items.{$it->id}.description", $it->description),
        'hs_code' => (string) old("items.{$it->id}.hs_code", $it->hs_code),
        'quantity' => (string) old("items.{$it->id}.quantity", rtrim(rtrim(number_format((float) $it->quantity, 3, ',', ''), '0'), ',')),
        'uom' => (string) old("items.{$it->id}.uom", $it->uom),
        'unit_price' => (string) old("items.{$it->id}.unit_price", rtrim(rtrim(number_format((float) ($it->base_unit_price ?? $it->unit_price), 4, ',', ''), '0'), ',')),
        'total' => (string) old("items.{$it->id}.total", number_format((float) ($it->base_total ?? $it->total), 2, ',', '')),
        'touched' => false,
    ])->values();
@endphp
<div x-data="{
        open: {{ $failed ? 'true' : 'false' }},
        rows: @js($rowsData),
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
            <p class="text-xs text-muted">{{ $hasTerms ? __('Satıcının ilkin rəqəmlərini dəyişirsiniz — navlun və endirim yenidən bölünəcək.') : __('Total, Quantity × Unit Price ilə hesablanır; əl ilə də yazmaq olar.') }} {{ __('Saxlananda logistika və komissiya yenidən hesablanır.') }}</p>
        </header>
        @if($failed)<p class="px-5 pt-3 field-error">{{ collect($errors->getMessages())->filter(fn ($v, $k) => str_starts_with($k, 'items'))->flatten()->first() }}</p>@endif
        <div class="overflow-x-auto">
            <table class="table-g text-[13px]">
                <thead lang="en"><tr>
                    <th>N</th><th>Product Code</th><th>Description</th><th>HS Code</th><th class="!text-right">Quantity</th><th>UOM</th><th class="!text-right">Unit Price</th><th class="!text-right">Total/{{ $invoice->currency }}</th>
                </tr></thead>
                <tbody>
                <template x-for="r in rows" :key="r.id">
                    <tr>
                        <td class="font-mono" x-text="r.line_no"></td>
                        <td><input :name="'items[' + r.id + '][product_code]'" x-model="r.product_code" class="input font-mono min-w-[130px]" maxlength="64"></td>
                        <td><textarea :name="'items[' + r.id + '][description]'" x-model="r.description" rows="2" class="input min-w-[260px]" maxlength="255" required></textarea></td>
                        <td><input :name="'items[' + r.id + '][hs_code]'" x-model="r.hs_code" class="input font-mono min-w-[110px]" maxlength="20"></td>
                        <td><input :name="'items[' + r.id + '][quantity]'" x-model="r.quantity" @input="calc(r)" inputmode="decimal" class="input font-mono text-right min-w-[90px]" required></td>
                        <td><input :name="'items[' + r.id + '][uom]'" x-model="r.uom" class="input min-w-[70px]" maxlength="16"></td>
                        <td><input :name="'items[' + r.id + '][unit_price]'" x-model="r.unit_price" @input="calc(r)" inputmode="decimal" class="input font-mono text-right min-w-[110px]" required></td>
                        <td><input :name="'items[' + r.id + '][total]'" x-model="r.total" @input="r.touched = true" inputmode="decimal" class="input font-mono text-right min-w-[120px]"></td>
                    </tr>
                </template>
                </tbody>
                <tfoot><tr class="bg-surface-2 font-semibold">
                    <td class="px-4 py-3" colspan="7">{{ __('Cəmi') }}</td>
                    <td class="px-4 py-3 text-right font-mono" x-text="fmt(sum())"></td>
                </tr></tfoot>
            </table>
        </div>
        <footer class="px-5 py-3 border-t border-line flex justify-end gap-2">
            <button type="button" class="btn btn-ghost" @click="open = false">{{ __('Ləğv et') }}</button>
            <button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>
        </footer>
    </form>
</div>
