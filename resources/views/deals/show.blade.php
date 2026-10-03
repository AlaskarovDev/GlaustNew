<x-layouts.app :title="'Tədarük '.$deal->code">
    @php
        $supplierInvoices = $deal->invoices->where('type', 'supplier');
        $customerInvoices = $deal->invoices->where('type', 'customer');
        $importErrors = session('import_errors', []);
    @endphp
    <x-page-header :title="$deal->title" :back="route('projects.show', [$deal->project, 'tab' => 'deals'])"
                   :subtitle="'Tədarük '.$deal->code.' · '.azdate($deal->deal_date).' · Layihə '.$deal->project->code">
        <x-slot:actions>
            @can('projects.update')
                <a href="{{ route('deals.edit', $deal) }}" class="btn btn-secondary"><x-icon name="pencil" class="size-4"/> Redaktə</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
        <x-status group="deal" :value="$deal->status"/>
        <span class="badge badge-slate font-mono">{{ $deal->currency }}</span>
        @if($deal->responsible)<span class="badge badge-slate">{{ $deal->responsible->name }}</span>@endif
    </div>

    {{-- Flow: seller -> us -> buyer --}}
    <ol class="grid md:grid-cols-[1fr_auto_1fr_auto_1fr] items-stretch gap-3 mb-6" aria-label="Tədarük axını">
        <li class="card p-4">
            <div class="text-xs text-muted">Satıcı (məhsulu ondan alırıq)</div>
            <div class="font-semibold truncate">{{ $deal->supplier?->name ?? '—' }}</div>
            <div class="text-xs text-muted">{{ $deal->supplier?->country }}</div>
        </li>
        <li class="hidden md:grid place-items-center text-faint"><x-icon name="arrow-right" class="size-5"/></li>
        <li class="card p-4 bg-brand-soft/40 border-brand/30">
            <div class="text-xs text-muted">Vasitəçi</div>
            <div class="font-semibold truncate">{{ tenant()->name }}</div>
            <div class="text-xs text-muted">alış + xərclər → satış</div>
        </li>
        <li class="hidden md:grid place-items-center text-faint"><x-icon name="arrow-right" class="size-5"/></li>
        <li class="card p-4">
            <div class="text-xs text-muted">Alıcı (məhsulu ona satırıq)</div>
            <div class="font-semibold truncate">{{ $deal->counterparty?->name ?? '—' }}</div>
            <div class="text-xs text-muted">{{ $deal->counterparty?->country }}</div>
        </li>
    </ol>

    <div class="grid xl:grid-cols-2 gap-6 mb-6">
        @foreach([['Alış müqaviləsi', 'satıcı ilə', $deal->purchaseContract, 'bg-saffron'], ['Satış müqaviləsi', 'alıcı ilə', $deal->saleContract, 'bg-brand']] as [$title, $with, $c, $bar])
            <section class="card relative overflow-hidden">
                <div class="absolute inset-x-0 top-0 h-1 {{ $bar }}"></div>
                <header class="flex items-center justify-between px-5 pt-5">
                    <h2 class="text-sm font-semibold">{{ $title }} <span class="text-muted font-normal">— {{ $with }}</span></h2>
                    @if($c)<x-status group="contract" :value="$c->status"/>@endif
                </header>
                @if($c)
                    <div class="px-5 py-4">
                        <a href="{{ route('contracts.show', $c) }}" class="font-mono font-semibold hover:text-brand-ink">{{ $c->number }}</a>
                        <div class="text-sm text-ink-2">{{ $c->subject }}</div>
                        <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                            <span><span class="text-muted">Məbləğ:</span> <span class="font-mono">{{ money($c->amount, $c->currency) }}</span></span>
                            <span><span class="text-muted">AZN:</span> <span class="font-mono">{{ money($c->amount_azn) }}</span></span>
                            <span><span class="text-muted">Tarix:</span> <span class="font-mono">{{ azdate($c->contract_date) }}</span></span>
                        </div>
                        <div class="mt-4 space-y-1.5">
                            <div class="text-xs font-medium text-muted">Müqavilə sənədləri</div>
                            @forelse($c->attachments as $f)
                                <a href="{{ route('attachments.download', $f) }}" class="flex items-center gap-2 text-sm hover:text-brand-ink"><x-icon name="file-pdf" class="size-4 text-danger"/> {{ $f->original_name }} <span class="text-xs text-faint">{{ $f->humanSize() }}</span></a>
                            @empty
                                <p class="text-xs text-faint">İmzalı PDF yüklənməyib — «Redaktə» ilə əlavə edin.</p>
                            @endforelse
                            <a href="{{ route('contracts.pdf', $c) }}" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-ink hover:underline"><x-icon name="printer" class="size-3.5"/> Müqavilə kartı (PDF)</a>
                        </div>
                    </div>
                @else
                    <div class="px-5 py-6 text-sm text-muted">Seçilməyib. @can('projects.update')<a href="{{ route('deals.edit', $deal) }}" class="text-brand-ink hover:underline">Müqavilə seçin</a>@endcan</div>
                @endif
            </section>
        @endforeach
    </div>

    {{-- Invoices --}}
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
                            <td data-label="Status"><x-status group="invoice" :value="$inv->status"/></td>
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

    <section class="card p-5 mb-6 border-dashed">
        <h2 class="text-sm font-semibold">Alıcıya faktura <span class="badge badge-amber ml-1">Növbəti mərhələ</span></h2>
        <p class="mt-1 text-sm text-muted">Satıcının fakturası əsasında bizim xərclər (logistika, komissiya faizi, CCL, RUB çevirməsi) əlavə olunaraq alıcı üçün yeni faktura yaradılacaq və satış müqaviləsinə ({{ $deal->saleContract?->number ?? 'seçilməyib' }}) bağlanacaq. Hesablama qaydaları təsdiqləndikdən sonra aktivləşəcək.</p>
    </section>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        @include('partials.history', ['history' => $history])
        <div class="space-y-6">
            @if($deal->notes)<section class="card p-5"><h2 class="text-sm font-semibold mb-2">Qeydlər</h2><p class="text-sm whitespace-pre-line">{{ $deal->notes }}</p></section>@endif
            @include('partials.attachments', ['model' => $deal, 'type' => 'deal', 'ability' => 'projects.update'])
            @can('projects.delete')
                <x-delete-form :action="route('deals.destroy', $deal)" label="Tədarükü sil" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="'Tədarük '.$deal->code.' silinəcək. Fakturası olan tədarük silinmir.'"/>
            @endcan
        </div>
    </div>
</x-layouts.app>
