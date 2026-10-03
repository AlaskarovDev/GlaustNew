    <section class="card overflow-hidden mb-6">
        <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
            <div>
                <h2 class="text-base font-semibold">Satıcının fakturaları <span class="text-muted font-mono font-normal text-sm">{{ $supplierInvoices->count() }}</span></h2>
                <p class="text-xs text-muted">Satıcının bizə proformaları — alış müqaviləsinə ({{ $deal->purchaseContract?->number ?? 'seçilməyib' }}) bağlanır</p>
            </div>
            <a href="{{ route('invoices.template') }}" class="btn btn-secondary btn-sm"><x-icon name="download" class="size-4"/> Excel şablonu</a>
        </header>

        @if($supplierInvoices->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>Proforma №</th><th>Tarix</th><th class="!text-right">Sətir</th><th class="!text-right">Məbləğ</th><th class="!text-right">AZN (CBAR)</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($supplierInvoices as $inv)
                        <tr>
                            <td data-label="Proforma №"><a href="{{ route('invoices.show', $inv) }}" class="font-mono font-medium text-ink hover:text-brand-ink">{{ $inv->number }}</a></td>
                            <td data-label="Tarix" class="font-mono text-xs">{{ azdate($inv->invoice_date) }}</td>
                            <td data-label="Sətir" class="num">{{ $inv->items_count }}</td>
                            <td data-label="Məbləğ" class="num">{{ money($inv->total, $inv->currency) }}</td>
                            <td data-label="AZN" class="num">{{ money($inv->total_azn) }}<div class="text-[11px] text-faint">{{ rate_fmt($inv->cbar_rate) }}</div></td>
                            <td data-label="Status"><x-status group="invoice" :value="$inv->status"/>
                                @if($inv->approval_status)@php [$al, $at] = \App\Models\Invoice::APPROVAL_STATUSES[$inv->approval_status]; @endphp<span class="badge badge-{{ $at }} ml-1">{{ $al }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    @if($supplierInvoices->count() > 1)
                        <tfoot class="hidden md:table-footer-group"><tr class="bg-surface-2 font-semibold">
                            <td class="px-4 py-3" colspan="3">Cəmi</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money($supplierInvoices->sum('total'), $supplierInvoices->first()->currency) }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ money($supplierInvoices->sum('total_azn')) }}</td><td></td>
                        </tr></tfoot>
                    @endif
                </table>
            </div>
        @endif

        @can('projects.create')
            <form method="POST" action="{{ route('invoices.import', $deal) }}" enctype="multipart/form-data" class="p-5 border-t border-line bg-surface-2/50" x-data="{ name: '', busy: false }" @submit="busy = true">
                @csrf
                <h3 class="text-sm font-semibold mb-1">Satıcının fakturasını import et</h3>
                <p class="text-xs text-muted mb-4">Şablonda yalnız yaşıl sütunlar doldurulur: Proforma N, N, Description, HS Code, Quantity, UOM, Unit Price, Total/EUR. Faylda bir neçə proforma nömrəsi varsa, hər biri ayrıca faktura olur.</p>
                <div class="grid sm:grid-cols-[1fr_180px_auto] gap-3 items-end">
                    <label class="flex items-center gap-3 h-10 px-3 rounded-[10px] border border-dashed border-line-strong bg-surface cursor-pointer hover:border-brand min-w-0">
                        <x-icon name="sheet" class="size-5 text-success shrink-0"/>
                        <span class="text-sm truncate" :class="!name && 'text-faint'" x-text="name || 'Excel faylını seçin (.xlsx)'"></span>
                        <input type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" required @change="name = $event.target.files[0]?.name">
                    </label>
                    <x-input name="invoice_date" type="date" label="Faktura tarixi" :value="today()" required :max="today()->format('Y-m-d')"/>
                    <button class="btn btn-primary" :disabled="busy"><x-icon name="upload" class="size-4"/> Import et</button>
                </div>
                @error('file')<p class="field-error">{{ $message }}</p>@enderror
                @if($importErrors)
                    <div class="mt-4 rounded-xl border border-danger/30 bg-danger-soft/40 p-4" role="alert">
                        <div class="text-sm font-semibold text-danger mb-2">Faylda xətalar (heç nə yadda saxlanmadı):</div>
                        <ul class="text-xs space-y-1 max-h-60 overflow-y-auto">
                            @foreach($importErrors as $line => $msg)
                                <li><span class="font-mono text-muted">{{ $line ? $line.'-ci sətir' : 'Fayl' }}:</span> {{ $msg }}</li>
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
            <h2 class="text-base font-semibold">Alıcı üçün sənədlər <span class="text-muted font-mono font-normal text-sm">{{ $deal->salesDocuments->count() }}</span></h2>
            <p class="text-xs text-muted">Proforma faktura (EN) və spesifikasiya (RU) — satış müqaviləsinə ({{ $deal->saleContract?->number ?? 'seçilməyib' }}) görə; redaktə edilə bilən sənədlərdir</p>
        </header>
        @if($deal->salesDocuments->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>Sənəd</th><th>Nömrə</th><th>Tarix</th><th>Hesablama</th><th class="!text-right">Məbləğ</th><th class="w-40"></th></tr></thead>
                    <tbody>
                    @foreach($deal->salesDocuments as $d)
                        <tr>
                            <td data-label="Sənəd"><a href="{{ route('sales-documents.show', $d) }}" class="font-medium text-ink hover:text-brand-ink">{{ $d->title() }}</a><div class="text-[11px] text-muted">{{ $d->label() }}</div></td>
                            <td data-label="Nömrə" class="font-mono">{{ $d->number }}</td>
                            <td data-label="Tarix" class="font-mono text-xs">{{ azdate($d->doc_date) }}</td>
                            <td data-label="Hesablama">@if($inv = $supplierInvoices->firstWhere('id', $d->source_invoice_id))<a href="{{ route('invoices.show', $inv) }}" class="font-mono text-xs hover:text-brand-ink">{{ $inv->number }}</a>@else — @endif</td>
                            <td data-label="Məbləğ" class="num">{{ money($d->grandTotal(), $d->currency) }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('sales-documents.show', $d) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="size-3.5"/> Aç</a>
                                <a href="{{ route('sales-documents.pdf', $d) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="size-4"/> PDF</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-5 py-5 text-sm text-muted">Satıcının fakturasında logistika, komissiya və RUB konvertasiyası tətbiq olunan kimi proforma faktura və spesifikasiya burada avtomatik yaranacaq.</p>
        @endif
    </section>
