<x-layouts.app :title="$invoice->typeLabel().' '.$invoice->number" wide>
    @php $later = array_filter($columns, fn ($c) => ! $c[1]); @endphp
    <x-page-header :title="$invoice->typeLabel().' № '.$invoice->number" :back="route('deals.show', $invoice->deal)"
                   :subtitle="($invoice->counterparty?->name ?? '').' · '.azdate($invoice->invoice_date).' · Tədarük '.$invoice->deal->code">
        <x-slot:actions>
            <a href="{{ route('invoices.export', [$invoice, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
            <a href="{{ route('invoices.export', [$invoice, 'format' => 'pdf']) }}" class="btn btn-secondary"><x-icon name="file-pdf" class="size-4 text-danger"/> PDF</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-2 xl:grid-cols-5 gap-4 mb-6 stagger">
        <div class="card p-4" style="--i:0"><div class="text-xs text-muted">Cəmi</div><div class="text-xl font-semibold font-mono">{{ money($invoice->total, $invoice->currency) }}</div></div>
        <div class="card p-4" style="--i:1"><div class="text-xs text-muted">AZN (CBAR {{ azdate($invoice->invoice_date) }})</div><div class="text-xl font-semibold font-mono">{{ money($invoice->total_azn) }}</div><div class="text-[11px] text-faint">{{ rate_fmt($invoice->cbar_rate) }}</div></div>
        <div class="card p-4" style="--i:2"><div class="text-xs text-muted">Sətir / miqdar</div><div class="text-xl font-semibold font-mono">{{ $invoice->items->count() }}</div><div class="text-[11px] text-faint">{{ num($invoice->items->sum('quantity'), 2) }} cəmi miqdar</div></div>
        <div class="card p-4" style="--i:3"><div class="text-xs text-muted">Müqavilə</div>
            @if($invoice->contract)<a href="{{ route('contracts.show', $invoice->contract) }}" class="block font-mono font-semibold hover:text-brand-ink">{{ $invoice->contract->number }}</a>@else<div>—</div>@endif
            <div class="text-[11px] text-faint">{{ $invoice->type === 'supplier' ? 'alış müqaviləsi' : 'satış müqaviləsi' }}</div></div>
        <div class="card p-4" style="--i:4"><div class="text-xs text-muted">Status</div>
            @can('projects.update')
                <form method="POST" action="{{ route('invoices.status', $invoice) }}" x-data class="mt-1">@csrf @method('PUT')
                    <select name="status" class="input !h-8 text-sm" @change="$el.form.requestSubmit()" aria-label="Status">
                        @foreach(\App\Models\Invoice::STATUSES as $k => [$l])<option value="{{ $k }}" @selected($invoice->status === $k)>{{ $l }}</option>@endforeach
                    </select>
                </form>
            @else
                <x-status group="invoice" :value="$invoice->status"/>
            @endcan
        </div>
    </div>

    @if($invoice->type === 'supplier')
        <div class="flex items-baseline justify-between gap-3 mb-3">
            <h2 class="text-sm font-semibold text-ink-2">Hesablama addımları</h2>
            <p class="text-xs text-muted">Hər addım tətbiq olunduqca cədvəldəki uyğun sütunlar dolur</p>
        </div>
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6 items-stretch">
            @include('invoices._logistics')
            @include('invoices._commission')
            @include('invoices._rub')
        </div>
    @endif

    @php
        // Computed columns of the company's sheet; a column is live once its inputs exist.
        $liveCols = [
            'logistics' => $invoice->hasLogistics(),
            'unit_price_log' => $invoice->hasLogistics(),
            'fee' => $invoice->hasCommission(),
            'unit_price_ccl_eur' => $invoice->hasLogistics() && $invoice->hasCommission(),
            'total_ccl_eur' => $invoice->hasLogistics() && $invoice->hasCommission(),
            'unit_price_rur' => $invoice->rubReady(), 'total_rur' => $invoice->rubReady(),
            'unit_price_rur_rounded' => $invoice->rubReady(), 'total_rur_rounded' => $invoice->rubReady(),
        ];
        $hints = [
            'logistics' => 'Logistika xərcini daxil edin', 'unit_price_log' => '(Total + Logistics) / Quantity — logistika lazımdır',
            'fee' => 'Total × komissiya faizi — faizi sağdakı paneldə tətbiq edin',
            'unit_price_ccl_eur' => 'Total price CCL / Quantity — logistika və komissiya lazımdır', 'total_ccl_eur' => 'Total + Logistics + Komissiya — logistika və komissiya lazımdır',
            'unit_price_rur' => 'UNIT PRICE CCL × RUB məzənnəsi', 'total_rur' => 'UNIT PRICE RUR × Quantity',
            'unit_price_rur_rounded' => 'UNIT PRICE RUR 2 rəqəmə yuvarlaqlaşdırılmış', 'total_rur_rounded' => 'Yuvarlaq UNIT PRICE RUR × Quantity',
        ];
        $cell = fn ($it, $k) => match ($k) {
            'logistics' => $it->logistics, 'unit_price_log' => $it->unitPriceLog(), 'fee' => $it->commission,
            'unit_price_ccl_eur' => $it->unitPriceCcl(), 'total_ccl_eur' => $it->totalCcl(),
            'unit_price_rur' => $it->unitPriceRub(), 'total_rur' => $it->totalRub(),
            'unit_price_rur_rounded' => $it->unitPriceRubRounded(), 'total_rur_rounded' => $it->totalRubRounded(), default => $it->extra[$k] ?? null,
        };
        $summable = ['logistics', 'fee', 'total_ccl_eur', 'total_rur', 'total_rur_rounded'];
        $yellow = ['unit_price_rur_rounded', 'total_rur_rounded'];
        // The sheet's formulas, shown under each computed header (E = Quantity, H = Total).
        $formulas = [
            'logistics' => 'H × logistika / H cəm', 'unit_price_log' => '(I + H) / E', 'fee' => 'H × faiz',
            'unit_price_ccl_eur' => '(K + I + H) / E', 'total_ccl_eur' => 'L × E',
            'unit_price_rur' => 'L × '.$invoice->currency.'/RUB', 'total_rur' => 'N × E',
            'unit_price_rur_rounded' => 'ROUND(N; 2)', 'total_rur_rounded' => 'P × E',
        ];
        $label = fn ($k, $l) => $k === 'fee' && $invoice->hasCommission() ? 'Commission '.$invoice->commissionLabel().'%' : $l;
        $pending = collect($later)->keys()->reject(fn ($k) => $liveCols[$k] ?? false)->count();
    @endphp

    <section class="card overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="table-g text-[13px]">
                <thead lang="en">
                <tr>
                    @foreach($columns as $key => [$colLabel, $fillable])
                        @php $live = $fillable || ($liveCols[$key] ?? false); @endphp
                        <th @class(['!text-right' => in_array($key, ['quantity', 'unit_price', 'total']) || ! $fillable, '!bg-surface-2 !text-faint' => ! $live, '!bg-saffron-soft' => $key === 'logistics' && $live, '!bg-brand-soft' => $key === 'fee' && $live, '!bg-saffron-soft !text-ink' => in_array($key, $yellow, true) && $live])
                            title="{{ $fillable ? '' : ($hints[$key] ?? '') }}">{{ $label($key, $colLabel) }}@isset($formulas[$key])<span class="block mt-0.5 font-mono text-[10px] font-normal normal-case tracking-normal opacity-70">{{ $formulas[$key] }}</span>@endisset</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach($invoice->items as $it)
                    <tr>
                        <td class="font-mono">{{ $invoice->number }}</td>
                        <td class="font-mono">{{ $it->line_no }}</td>
                        <td class="min-w-[220px]">{{ $it->description }}</td>
                        <td class="font-mono">{{ $it->hs_code }}</td>
                        <td class="num">{{ num($it->quantity, 2) }}</td>
                        <td>{{ $it->uom }}</td>
                        <td class="num">{{ rtrim(rtrim(num($it->unit_price, 4), '0'), ',') }}</td>
                        <td class="num font-medium text-ink">{{ num($it->total) }}</td>
                        @foreach($later as $k => $c)
                            @php $v = ($liveCols[$k] ?? false) ? $cell($it, $k) : ($it->extra[$k] ?? null); @endphp
                            @if($liveCols[$k] ?? false)
                                <td @class(['num font-medium text-ink', 'bg-saffron-soft/40' => $k === 'logistics' || in_array($k, $yellow, true), 'bg-brand-soft/40' => $k === 'fee'])>{{ $v === null ? '—' : num($v) }}
                                    @if($k === 'logistics' && $invoice->logistics_currency !== $invoice->currency)<div class="text-[11px] text-faint">{{ num($it->logistics_original) }} {{ $invoice->logistics_currency }}</div>@endif
                                </td>
                            @else
                                <td class="num text-faint bg-surface-2/60">{{ $v === null ? '—' : num($v) }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr class="bg-surface-2 font-semibold">
                    <td class="px-4 py-3" colspan="4">Cəmi</td>
                    <td class="px-4 py-3 text-right font-mono">{{ num($invoice->items->sum('quantity'), 2) }}</td>
                    <td colspan="2"></td>
                    <td class="px-4 py-3 text-right font-mono">{{ num($invoice->items->sum('total')) }}</td>
                    @foreach($later as $k => $c)
                        @if(($liveCols[$k] ?? false) && in_array($k, $summable, true))
                            <td @class(['px-4 py-3 text-right font-mono', 'bg-saffron-soft/40' => $k === 'logistics' || in_array($k, $yellow, true), 'bg-brand-soft/40' => $k === 'fee'])>{{ num($invoice->items->sum(fn ($it) => (float) $cell($it, $k))) }}</td>
                        @else
                            <td></td>
                        @endif
                    @endforeach
                </tr>
                </tfoot>
            </table>
        </div>
        @if($pending)
            <p class="px-5 py-3 border-t border-line text-xs text-muted">
                @if(! $invoice->hasLogistics() && ! $invoice->hasCommission()) Boz sütunlar logistika xərci və komissiya faizi daxil edildikdən sonra hesablanır.
                @elseif(! $invoice->hasLogistics()) UNIT PRICE+LOG və CCL sütunları üçün logistika xərcini daxil edin.
                @elseif(! $invoice->hasCommission()) Commission və CCL sütunları üçün sağdakı paneldə komissiya faizini tətbiq edin.
                @elseif(! $invoice->hasRub()) RUR sütunları üçün sağdakı «RUB-a çevirmə» panelində məzənnəni tətbiq edin.
                @endif
            </p>
        @endif
    </section>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        @include('partials.history', ['history' => $history])
        <div class="space-y-6">
            @include('partials.attachments', ['model' => $invoice, 'type' => 'invoice', 'ability' => 'projects.update'])
            @can('projects.delete')
                <x-delete-form :action="route('invoices.destroy', $invoice)" label="Fakturanı sil" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="'Faktura '.$invoice->number.' və bütün sətirləri silinəcək.'"/>
            @endcan
        </div>
    </div>
</x-layouts.app>
