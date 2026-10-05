    {{-- Once an invoice exists the import form folds into a button (a second proforma can still come in). --}}
    <section class="card overflow-hidden mb-6" x-data="{ importing: {{ $supplierInvoices->isEmpty() || session('import_errors') || $errors->has('file') ? 'true' : 'false' }} }">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
            <div>
                <h2 class="text-base font-semibold">{{ __('Satıcının fakturaları') }} <span class="text-muted font-mono font-normal text-sm">{{ $supplierInvoices->count() }}</span></h2>
                <p class="text-xs text-muted">{{ __('Satıcının bizə proformaları — alış müqaviləsinə (') }}{{ $deal->purchaseContract?->number ?? __('seçilməyib') }}{{ __(') bağlanır') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('invoices.template') }}" class="btn btn-secondary btn-sm"><x-icon name="download" class="size-4"/> {{ __('Excel şablonu') }}</a>
                @if($supplierInvoices->isNotEmpty())
                    @can('projects.create')<button type="button" class="btn btn-primary btn-sm" @click="importing = !importing" :aria-expanded="importing"><x-icon name="upload" class="size-4"/> <span x-text="importing ? {{ \Illuminate\Support\Js::from(__('Bağla')) }} : 'Faktura import et'"></span></button>@endcan
                @endif
            </div>
        </header>

        @if($supplierInvoices->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>{{ __('Proforma №') }}</th><th>{{ __('Tarix') }}</th><th class="!text-right">{{ __('Sətir') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach($supplierInvoices as $inv)
                        <tr class="cursor-pointer hover:bg-surface-2/60" @click="if (!$event.target.closest('a, button')) window.location = @js(route('invoices.show', $inv))">
                            <td data-label="Proforma №"><a href="{{ route('invoices.show', $inv) }}" class="font-mono font-medium text-ink hover:text-brand-ink">{{ $inv->number }}</a></td>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($inv->invoice_date) }}</td>
                            <td data-label="{{ __('Sətir') }}" class="num">{{ $inv->items_count }}</td>
                            <td data-label="{{ __('Məbləğ') }}" class="num">{{ money($inv->total, $inv->currency) }}</td>
                            <td data-label="Status"><x-status group="invoice" :value="$inv->status"/>
                                @if($inv->approval_status)@php [$al, $at] = \App\Models\Invoice::APPROVAL_STATUSES[$inv->approval_status]; @endphp<span class="badge badge-{{ $at }} ml-1">{{ __($al) }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    @if($supplierInvoices->count() > 1)
                        <tfoot class="hidden md:table-footer-group"><tr class="bg-surface-2 font-semibold">
                            <td class="px-4 py-3" colspan="3">{{ __('Cəmi') }}</td>
                            {{-- per invoice currency, never converted --}}
                            <td class="px-4 py-3 text-right font-mono">{!! $supplierInvoices->groupBy('currency')->map(fn ($g, $c) => e(money($g->sum('total'), $c)))->implode('<br>') !!}</td><td></td>
                        </tr></tfoot>
                    @endif
                </table>
            </div>
        @endif

        @can('projects.create')
            <form method="POST" action="{{ route('invoices.import', $deal) }}" x-show="importing" x-collapse @if($supplierInvoices->isNotEmpty() && ! session('import_errors') && ! $errors->has('file')) x-cloak @endif enctype="multipart/form-data" class="p-5 border-t border-line bg-surface-2/50" x-data="{ name: '', busy: false }" @submit="busy = true">
                @csrf
                <h3 class="text-sm font-semibold mb-1">{{ __('Satıcının fakturasını import et') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Şablonda yalnız yaşıl sütunlar doldurulur: Proforma N, N, Description, HS Code, Quantity, UOM, Unit Price, Total/EUR. Faylda bir neçə proforma nömrəsi varsa, hər biri ayrıca faktura olur.') }}</p>
                <div class="grid sm:grid-cols-[1fr_180px_auto] gap-3 items-end">
                    <label class="flex items-center gap-3 h-10 px-3 rounded-[10px] border border-dashed border-line-strong bg-surface cursor-pointer hover:border-brand min-w-0">
                        <x-icon name="sheet" class="size-5 text-success shrink-0"/>
                        <span class="text-sm truncate" :class="!name && 'text-faint'" x-text="name || {{ \Illuminate\Support\Js::from(__('Excel faylını seçin (.xlsx)')) }}"></span>
                        <input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" required @change="name = $event.target.files[0]?.name">
                    </label>
                    <x-input name="invoice_date" type="date" :label="__('Faktura tarixi')" :value="today()" required :max="today()->format('Y-m-d')"/>
                    <button class="btn btn-primary" :disabled="busy"><x-icon name="upload" class="size-4"/> {{ __('Import et') }}</button>
                </div>
                @error('file')<p class="field-error">{{ $message }}</p>@enderror
                @if($importErrors)
                    <div class="mt-4 rounded-xl border border-danger/30 bg-danger-soft/40 p-4" role="alert">
                        <div class="text-sm font-semibold text-danger mb-2">{{ __('Faylda xətalar (heç nə yadda saxlanmadı):') }}</div>
                        <ul class="text-xs space-y-1 max-h-60 overflow-y-auto">
                            @foreach($importErrors as $line => $msg)
                                <li><span class="font-mono text-muted">{{ $line ? $line.__('-ci sətir') : __('Fayl') }}:</span> {{ $msg }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </form>
        @endcan
    </section>


    {{-- Documents for the buyer, generated from each calculated seller invoice --}}
    <section class="card overflow-hidden mb-6">
        <header class="px-5 py-4 border-b border-line">
            <h2 class="text-base font-semibold">{{ __('Alıcı üçün sənədlər') }} <span class="text-muted font-mono font-normal text-sm">{{ $deal->salesDocuments->count() }}</span></h2>
            <p class="text-xs text-muted">{{ __('Proforma faktura (EN) və spesifikasiya (RU) — satış müqaviləsinə (') }}{{ $deal->saleContract?->number ?? __('seçilməyib') }}{{ __(') görə; redaktə edilə bilən sənədlərdir') }}</p>
        </header>
        @if($deal->salesDocuments->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>{{ __('Sənəd') }}</th><th>{{ __('Nömrə') }}</th><th>{{ __('Tarix') }}</th><th>{{ __('Hesablama') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th class="w-40"></th></tr></thead>
                    <tbody>
                    @foreach($deal->salesDocuments as $d)
                        <tr>
                            <td data-label="{{ __('Sənəd') }}"><a href="{{ route('sales-documents.show', $d) }}" class="font-medium text-ink hover:text-brand-ink">{{ $d->title() }}</a><div class="text-[11px] text-muted">{{ $d->label() }}</div></td>
                            <td data-label="{{ __('Nömrə') }}" class="font-mono">{{ $d->number }}</td>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($d->doc_date) }}</td>
                            <td data-label="{{ __('Hesablama') }}">@if($inv = $supplierInvoices->firstWhere('id', $d->source_invoice_id))<a href="{{ route('invoices.show', $inv) }}" class="font-mono text-xs hover:text-brand-ink">{{ $inv->number }}</a>@else — @endif</td>
                            <td data-label="{{ __('Məbləğ') }}" class="num">{{ $d->summary() }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('sales-documents.show', $d) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="size-3.5"/> {{ __('Aç') }}</a>
                                <a href="{{ route('sales-documents.pdf', $d) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-4"/> PDF</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-5 py-5 text-sm text-muted">{{ __('Satıcının fakturasında logistika, komissiya və RUB konvertasiyası tətbiq olunan kimi proforma faktura və spesifikasiya burada avtomatik yaranacaq.') }}</p>
        @endif
    </section>

    {{-- Proforma vs commercial invoice, and every change of their totals --}}
    @php
        $revisions = \App\Models\SalesDocumentRevision::where('deal_id', $deal->id)->with('user', 'document')->orderByDesc('id')->get();
        $pairs = $deal->salesDocuments->where('kind', 'proforma')->map(fn ($pf) => [
            'proforma' => $pf,
            'commercial' => $deal->salesDocuments->where('source_invoice_id', $pf->source_invoice_id)->firstWhere('kind', 'commercial'),
            'first' => (float) ($revisions->where('sales_document_id', $pf->id)->last()?->total_before ?? $pf->grandTotal()),
        ]);
    @endphp
    @if($pairs->isNotEmpty())
        <section class="card overflow-hidden mb-6" x-data="{ history: false }">
            <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
                <div>
                    <h2 class="text-base font-semibold">{{ __('Proforma və Commercial Invoice məbləğləri') }}</h2>
                    <p class="text-xs text-muted">{{ __('Proformanın ilk və cari məbləği, Commercial Invoice ilə fərq; hər dəyişiklik fərqi ilə yadda saxlanılır') }}</p>
                </div>
                @if($revisions->isNotEmpty())
                    <button type="button" class="btn btn-ghost btn-sm" @click="history = !history"><x-icon name="history" class="size-4"/> {{ __('Dəyişikliklər') }} ({{ $revisions->count() }})</button>
                @endif
            </header>
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>{{ __('Proforma') }}</th><th class="!text-right">{{ __('İlk məbləğ') }}</th><th class="!text-right">{{ __('Cari məbləğ') }}</th><th class="!text-right">{{ __('Dəyişiklik') }}</th><th>Commercial Invoice</th><th class="!text-right">{{ __('Məbləğ') }}</th><th class="!text-right">{{ __('Fərq (CI − proforma)') }}</th></tr></thead>
                    <tbody>
                    @foreach($pairs as $row)
                        @php $pf = $row['proforma']; $ci = $row['commercial']; $cur = $pf->grandTotal(); $change = round($cur - $row['first'], 2); $gap = $ci ? round($ci->grandTotal() - $cur, 2) : null; @endphp
                        <tr>
                            <td data-label="{{ __('Proforma') }}"><a href="{{ route('sales-documents.show', $pf) }}" class="font-mono font-medium hover:text-brand-ink">{{ $pf->number }}</a></td>
                            <td data-label="{{ __('İlk məbləğ') }}" class="num">{{ money($row['first'], $pf->currency) }}</td>
                            <td data-label="{{ __('Cari məbləğ') }}" class="num font-medium">{{ money($cur, $pf->currency) }}</td>
                            <td data-label="{{ __('Dəyişiklik') }}" @class(['num', 'text-success' => $change > 0, 'text-danger' => $change < 0])>{{ $change ? ($change > 0 ? '+' : '−').money(abs($change), $pf->currency) : '—' }}</td>
                            <td data-label="Commercial Invoice">@if($ci)<a href="{{ route('sales-documents.show', $ci) }}" class="font-mono hover:text-brand-ink">{{ $ci->number }}</a>@else <span class="text-muted text-xs">{{ __('hələ yoxdur') }}</span> @endif</td>
                            <td data-label="{{ __('Məbləğ') }}" class="num">{{ $ci ? money($ci->grandTotal(), $ci->currency) : '—' }}</td>
                            <td data-label="{{ __('Fərq (CI − proforma)') }}" @class(['num font-semibold', 'text-success' => $gap > 0, 'text-danger' => $gap < 0])>{{ $gap === null ? '—' : ($gap ? ($gap > 0 ? '+' : '−').money(abs($gap), $pf->currency) : money(0, $pf->currency)) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($revisions->isNotEmpty())
                <ol x-show="history" x-cloak class="border-t border-line divide-y divide-line text-sm">
                    @foreach($revisions as $rv)
                        <li class="px-5 py-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-medium">{{ $rv->document?->title() }} {{ $rv->document?->number }}</span>
                            <span class="font-mono text-xs text-muted">{{ money($rv->total_before, $rv->currency) }} → {{ money($rv->total_after, $rv->currency) }}</span>
                            <span @class(['font-mono text-xs font-semibold', 'text-success' => $rv->difference > 0, 'text-danger' => $rv->difference < 0])>{{ $rv->difference > 0 ? '+' : '−' }}{{ money(abs($rv->difference), $rv->currency) }}</span>
                            <span class="text-xs text-faint ml-auto">{{ $rv->user?->name ?? __('Sistem') }} · {{ azdate($rv->created_at, true) }}</span>
                            <span class="w-full text-xs text-muted">{{ $rv->reason ?: __('Əl ilə düzəliş') }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
