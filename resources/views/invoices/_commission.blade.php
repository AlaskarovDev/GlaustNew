{{-- Our commission %: line Total × % (the sheet's =H*0.035, with our own rate). Independent of the logistics cost. --}}
@php $grand = (float) $invoice->items->sum('total'); @endphp
<section class="card flex flex-col" x-data="{
        rate: @js((string) old('commission_rate', $invoice->hasCommission() ? $invoice->commissionLabel() : '')),
        grand: {{ $grand }},
        num(v) { return parseFloat(String(v ?? '').replace(/[\s%]/g, '').replace(',', '.')); },
        valid() { const r = this.num(this.rate); return !isNaN(r) && r >= 0 && r <= 100; },
        preview() { return this.valid() ? this.grand * this.num(this.rate) / 100 : 0; },
        fmt: (v) => glaustFmt.fmt(v, 2),
     }">
    <header class="px-5 py-4 border-b border-line">
        <h2 class="text-base font-semibold flex items-center gap-2"><x-icon name="percent" class="size-5 text-brand"/> Komissiya faizi</h2>
        <p class="text-xs text-muted">Hər sətir: Total/{{ $invoice->currency }} × faiz. Logistikadan əvvəl və ya sonra tətbiq etmək olar.</p>
    </header>

    <div class="p-5 space-y-4 flex-1">
        @if($invoice->hasCommission())
            <div class="flex items-baseline justify-between gap-3">
                <span class="badge badge-green">Tətbiq olunub: {{ $invoice->commissionLabel() }}%</span>
                <span class="font-mono font-semibold">{{ money($invoice->commission_total, $invoice->currency) }}</span>
            </div>
        @endif

        @can('projects.update')
            <form method="POST" action="{{ route('invoices.commission', $invoice) }}" class="space-y-3">
                @csrf
                <x-field label="Bizim komissiya faizimiz" name="commission_rate" required>
                    <div class="relative">
                        <input name="commission_rate" x-model="rate" inputmode="decimal" placeholder="Məs: 3,5" autocomplete="off"
                               class="input font-mono text-right !pr-9 @error('commission_rate') is-invalid @enderror">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted font-mono pointer-events-none">%</span>
                    </div>
                </x-field>
                <p class="text-xs text-muted" x-show="valid()">
                    Cəmi komissiya: <span class="font-mono text-ink" x-text="fmt(preview()) + ' {{ $invoice->currency }}'"></span>
                </p>
                <button class="btn btn-primary w-full" :disabled="!valid()"><x-icon name="check" class="size-4"/> Tətbiq et</button>
            </form>
            @if($invoice->hasCommission())
                <form method="POST" action="{{ route('invoices.commission.clear', $invoice) }}" data-confirm="Komissiya bütün sətirlərdən silinsin?" data-confirm-action="Sil">
                    @csrf @method('DELETE')
                    <button class="text-xs text-danger hover:underline">Komissiyanı sil</button>
                </form>
            @endif
        @elseif(! $invoice->hasCommission())
            <p class="text-sm text-muted">Komissiya hələ tətbiq olunmayıb.</p>
        @endcan
    </div>
</section>
