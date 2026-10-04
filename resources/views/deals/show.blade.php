<x-layouts.app :title="'Trade '.$deal->code">
    @php
        $supplierInvoices = $deal->invoices->where('type', 'supplier');
        $customerInvoices = $deal->invoices->where('type', 'customer');
        $importErrors = session('import_errors', []);
    @endphp
    <x-page-header :title="$deal->title" :back="route('projects.show', [$deal->project, 'tab' => 'deals'])"
                   :subtitle="'Trade '.$deal->code.' · '.azdate($deal->deal_date).' · Layihə '.$deal->project->code">
        <x-slot:actions>
            @can('projects.update')
                <a href="{{ route('deals.edit', $deal) }}" class="btn btn-secondary"><x-icon name="pencil" class="size-4"/> {{ __('Redaktə') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
        <x-status group="deal" :value="$deal->status"/>
        <span class="badge badge-slate font-mono">{{ $deal->currency }}</span>
        @if($deal->responsible)<span class="badge badge-slate">{{ $deal->responsible->name }}</span>@endif
    </div>

    {{-- Flow: seller -> us -> buyer --}}
    <ol class="grid md:grid-cols-[1fr_auto_1fr_auto_1fr] items-stretch gap-3 mb-6" aria-label="{{ __('Trade axını') }}">
        <li class="card p-4">
            <div class="text-xs text-muted">{{ __('Satıcı (məhsulu ondan alırıq)') }}</div>
            <div class="font-semibold truncate">{{ $deal->supplier?->name ?? '—' }}</div>
            <div class="text-xs text-muted">{{ $deal->supplier?->country }}@if($deal->purchaseContract) · <a href="{{ route('deals.show', [$deal, 'tab' => 'contracts']) }}" class="font-mono hover:text-brand-ink">{{ $deal->purchaseContract->number }}</a>@endif</div>
        </li>
        <li class="hidden md:grid place-items-center text-faint"><x-icon name="arrow-right" class="size-5"/></li>
        <li class="card p-4 bg-brand-soft/40 border-brand/30">
            <div class="text-xs text-muted">{{ __('Vasitəçi') }}</div>
            <div class="font-semibold truncate">{{ tenant()->name }}</div>
            <div class="text-xs text-muted">{{ __('alış + xərclər → satış') }}</div>
        </li>
        <li class="hidden md:grid place-items-center text-faint"><x-icon name="arrow-right" class="size-5"/></li>
        <li class="card p-4">
            <div class="text-xs text-muted">{{ __('Alıcı (məhsulu ona satırıq)') }}</div>
            <div class="font-semibold truncate">{{ $deal->counterparty?->name ?? '—' }}</div>
            <div class="text-xs text-muted">{{ $deal->counterparty?->country }}@if($deal->saleContract) · <a href="{{ route('deals.show', [$deal, 'tab' => 'contracts']) }}" class="font-mono hover:text-brand-ink">{{ $deal->saleContract->number }}</a>@endif</div>
        </li>
    </ol>

    {{-- Work in its natural order: 1 invoices & documents -> 2 the buyer pays -> 3 logistics --}}
    @php
        $received = $deal->payments->groupBy('currency')->map(fn ($g) => $g->sum('amount'));
        $tabs = [
            'invoices' => ['1', 'Fakturalar', $supplierInvoices->count() + $deal->salesDocuments->count()],
            'income' => ['2', 'Mədaxillər', $deal->payments->count()],
            'logistics' => ['3', 'Logistika', $deal->logisticsActs->count()],
            'contracts' => [null, 'Müqavilələr', collect([$deal->purchaseContract, $deal->saleContract])->filter()->count()],
        ];
    @endphp
    @include('deals._obligations')

    <nav class="deal-steps mb-6" aria-label="{{ __('Trade bölmələri') }}">
        @foreach($tabs as $key => [$no, $label, $count])
            <a href="{{ route('deals.show', [$deal, 'tab' => $key]) }}" @class(['deal-step', 'is-active' => $tab === $key]) @if($tab === $key) aria-current="page" @endif>
                <span class="deal-step-no">@if($no){{ $no }}@else<x-icon name="signature" class="size-4"/>@endif</span>
                <span class="font-medium">{{ $label }}</span>
                @if($count)<span class="deal-step-count">{{ $count }}</span>@endif
            </a>
            @if($key === 'logistics')<span class="mx-1 h-6 w-px bg-line shrink-0" aria-hidden="true"></span>@elseif(! $loop->last)<x-icon name="chevron-right" class="size-4 text-faint shrink-0 hidden sm:block"/>@endif
        @endforeach
    </nav>

    @if($tab === 'invoices')
        @include('deals._tab-invoices')
    @elseif($tab === 'income')
        @include('deals._tab-income')
    @elseif($tab === 'logistics')
        @include('deals._tab-logistics')
    @else
        @include('deals._tab-contracts')
    @endif

    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        @include('partials.history', ['history' => $history])
        <div class="space-y-6">
            @if($deal->notes)<section class="card p-5"><h2 class="text-sm font-semibold mb-2">{{ __('Qeydlər') }}</h2><p class="text-sm whitespace-pre-line">{{ $deal->notes }}</p></section>@endif
            @include('partials.attachments', ['model' => $deal, 'type' => 'deal', 'ability' => 'projects.update'])
            @can('projects.delete')
                <x-delete-form :action="route('deals.destroy', $deal)" :label="__('Trade-i sil')" :button="__('btn btn-ghost w-full text-danger hover:!bg-danger-soft')" :message="'Trade '.$deal->code.' silinəcək. Fakturası olan Trade silinmir.'"/>
            @endcan
        </div>
    </div>
</x-layouts.app>
