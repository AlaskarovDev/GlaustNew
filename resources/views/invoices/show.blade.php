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

    <section class="card overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="table-g text-[13px]">
                <thead lang="en">
                <tr>
                    @foreach($columns as $key => [$label, $fillable])
                        <th @class(['!text-right' => in_array($key, ['quantity', 'unit_price', 'total']) || ! $fillable, '!bg-surface-2 !text-faint' => ! $fillable]) title="{{ $fillable ? '' : 'Hesablama qaydası sonra əlavə olunacaq' }}">{{ $label }}</th>
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
                            <td class="num text-faint bg-surface-2/60">{{ isset($it->extra[$k]) ? num($it->extra[$k]) : '—' }}</td>
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
                    <td colspan="{{ count($later) }}" class="px-4 py-3 text-xs font-normal text-muted">Boz sütunlar növbəti mərhələdə (alıcıya faktura) hesablanacaq.</td>
                </tr>
                </tfoot>
            </table>
        </div>
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
