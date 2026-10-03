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

    {{-- Work in its natural order: 1 invoices & documents -> 2 the buyer pays -> 3 logistics --}}
    @php
        $received = $deal->payments->groupBy('currency')->map(fn ($g) => $g->sum('amount'));
        $tabs = [
            'invoices' => ['1', 'Fakturalar', $supplierInvoices->count() + $deal->salesDocuments->count()],
            'income' => ['2', 'Mədaxillər', $deal->payments->count()],
            'logistics' => ['3', 'Logistika', null],
        ];
    @endphp
    <nav class="deal-steps mb-6" aria-label="Tədarük bölmələri">
        @foreach($tabs as $key => [$no, $label, $count])
            <a href="{{ route('deals.show', [$deal, 'tab' => $key]) }}" @class(['deal-step', 'is-active' => $tab === $key]) @if($tab === $key) aria-current="page" @endif>
                <span class="deal-step-no">{{ $no }}</span>
                <span class="font-medium">{{ $label }}</span>
                @if($count)<span class="deal-step-count">{{ $count }}</span>@endif
            </a>
            @if(! $loop->last)<x-icon name="chevron-right" class="size-4 text-faint shrink-0 hidden sm:block"/>@endif
        @endforeach
    </nav>

    @if($tab === 'invoices')
        @include('deals._tab-invoices')
    @elseif($tab === 'income')
        @include('deals._tab-income')
    @else
        @include('deals._tab-logistics')
    @endif

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
