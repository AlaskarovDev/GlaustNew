<x-layouts.app :title="$project->name" :wide="$tab === 'board'">
    @php $profit = \App\Support\ProjectForecast::profit($deals); @endphp
    <x-page-header :title="$project->name" :back="route('projects.index')" :subtitle="'Layihə '.$project->code">
        <x-slot:actions>
            @can('projects.update')
                <a href="{{ route('projects.edit', $project) }}" class="btn btn-secondary"><x-icon name="pencil" class="size-4"/> Redaktə</a>
            @endcan
            @can('projects.create')
                <a href="{{ route('deals.create', $project) }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Yeni Trade</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Who the project runs with --}}
    <div class="grid md:grid-cols-[1fr_auto_1fr_auto_1fr] items-stretch gap-3 mb-4">
        <div class="card p-4 min-w-0">
            <div class="text-xs text-muted">Satıcı (məhsulu ondan alırıq)</div>
            @if($project->supplier)<a href="{{ route('counterparties.show', $project->supplier) }}" class="font-semibold truncate block hover:text-brand-ink">{{ $project->supplier->name }}</a>@else<div class="font-semibold">—</div>@endif
            <div class="text-xs text-muted">{{ $project->supplier?->country }}@if($project->purchaseContract) · <a href="{{ route('contracts.show', $project->purchaseContract) }}" class="font-mono hover:text-brand-ink">{{ $project->purchaseContract->number }}</a>@endif</div>
        </div>
        <div class="hidden md:grid place-items-center text-faint"><x-icon name="arrow-right" class="size-5"/></div>
        <div class="card p-4 bg-brand-soft/40 border-brand/30 min-w-0">
            <div class="text-xs text-muted">Məsul şəxs</div>
            <div class="flex items-center gap-2 mt-0.5">@if($project->manager)<x-avatar :user="$project->manager" size="sm"/><span class="font-semibold truncate">{{ $project->manager->name }}</span>@else<span class="font-semibold">—</span>@endif</div>
            <div class="text-xs text-muted mt-1" title="Komissiya (bugünkü CBAR ilə AZN) − Trade-lərə yazılan xərclər">Proqnoz mənfəət: <span @class(['font-mono font-medium', 'text-success' => $profit > 0, 'text-danger' => $profit !== null && $profit < 0])>{{ $profit === null ? '—' : '≈ '.money($profit) }}</span></div>
        </div>
        <div class="hidden md:grid place-items-center text-faint"><x-icon name="arrow-right" class="size-5"/></div>
        <div class="card p-4 min-w-0">
            <div class="text-xs text-muted">Alıcı (məhsulu ona satırıq)</div>
            @if($project->counterparty)<a href="{{ route('counterparties.show', $project->counterparty) }}" class="font-semibold truncate block hover:text-brand-ink">{{ $project->counterparty->name }}</a>@else<div class="font-semibold">—</div>@endif
            <div class="text-xs text-muted">{{ $project->counterparty?->country }}@if($project->saleContract) · <a href="{{ route('contracts.show', $project->saleContract) }}" class="font-mono hover:text-brand-ink">{{ $project->saleContract->number }}</a>@endif</div>
        </div>
    </div>

    <nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="Layihə bölmələri">
        @foreach(['deals' => 'Trade-lər ('.$deals->count().')', 'finance' => 'Maliyyə', 'board' => 'Tapşırıqlar ('.$stats['total'].')', 'files' => 'Fayllar və tarixçə'] as $key => $label)
            @if($key !== 'finance' || $finance)
                <a href="{{ route('projects.show', [$project, 'tab' => $key]) }}" @class(['tab-link', 'is-active' => $tab === $key])>{{ $label }}</a>
            @endif
        @endforeach
    </nav>

    @if($tab === 'deals')
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <p class="text-sm text-muted max-w-2xl">Trade — məhsulun satıcıdan alınıb alıcıya satıldığı bir partiyadır: hər iki tərəflə müqavilələr, fakturalar, ödənişlər və logistika bir yerdə.</p>
        </div>
        @if($deals->isEmpty())
            <div class="card"><x-empty icon="package" title="Hələ Trade yoxdur" text="Yeni Trade yaradın: tərəflər və müqavilələr layihədən avtomatik gələcək, sonra satıcının fakturasını Excel-dən import edəcəksiniz.">
                @can('projects.create')<a href="{{ route('deals.create', $project) }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Trade yarat</a>@endcan
            </x-empty></div>
        @else
            <div class="grid lg:grid-cols-2 gap-5 stagger">
                @foreach($deals as $d)
                    <a href="{{ route('deals.show', $d) }}" id="deal-{{ $d->id }}" class="card card-hover p-5 group deal-card scroll-mt-24" style="--i:{{ $loop->index }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs font-mono text-muted">{{ $d->code }} · {{ azdate($d->deal_date) }}</div>
                                <h3 class="mt-1 font-semibold group-hover:text-brand-ink truncate">{{ $d->title }}</h3>
                            </div>
                            <x-status group="deal" :value="$d->status"/>
                        </div>
                        <div class="mt-4 grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-sm">
                            <div class="min-w-0"><div class="text-[11px] text-muted">Satıcı</div><div class="truncate">{{ $d->supplier?->name ?? '—' }}</div><div class="text-[11px] font-mono text-faint">{{ $d->purchaseContract?->number ?? 'müqavilə yoxdur' }}</div></div>
                            <x-icon name="arrow-right" class="size-4 text-faint"/>
                            <div class="min-w-0"><div class="text-[11px] text-muted">Alıcı</div><div class="truncate">{{ $d->counterparty?->name ?? '—' }}</div><div class="text-[11px] font-mono text-faint">{{ $d->saleContract?->number ?? 'müqavilə yoxdur' }}</div></div>
                        </div>
                        @php $dealProfit = \App\Support\ProjectForecast::profit(collect([$d])); @endphp
                        <div class="mt-3 text-xs text-muted">Proqnoz mənfəət: <span @class(['font-mono font-medium', 'text-success' => $dealProfit > 0, 'text-danger' => $dealProfit !== null && $dealProfit < 0])>{{ $dealProfit === null ? '—' : '≈ '.money($dealProfit) }}</span></div>
                        <div class="mt-3 pt-3 border-t border-line flex justify-between text-sm">
                            <span class="text-muted">{{ $d->invoices_count }} faktura</span>
                            <span class="font-mono text-right">{!! $d->invoices->isEmpty() ? '—' : $d->invoices->groupBy('currency')->map(fn ($g, $c) => e(money($g->sum('total'), $c)))->implode('<br>') !!}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

    @elseif($tab === 'board')
        @include('tasks._board', ['columns' => $tasks->groupBy('status'), 'project' => $project])

    @elseif($tab === 'finance' && $finance)
        @php
            $budget = $finance['budget_azn'];
            $used = $budget ? min(100, round($finance['expense'] / max(0.01, $budget) * 100)) : null;
        @endphp
        <div class="grid sm:grid-cols-3 gap-4 mb-6 stagger">
            <div class="card p-5" style="--i:0"><div class="text-xs text-muted">Büdcə (AZN)</div><div class="mt-1 text-2xl font-semibold font-mono">{{ $budget !== null ? money($budget) : '—' }}</div>
                <div class="text-xs text-muted mt-1">{{ $project->currency !== 'AZN' ? money($project->budget, $project->currency).' · bugünkü CBAR məzənnəsi' : 'layihə büdcəsi' }}</div></div>
            <div class="card p-5" style="--i:1"><div class="text-xs text-muted">Faktiki xərc</div><div class="mt-1 text-2xl font-semibold font-mono text-danger">{{ money($finance['expense']) }}</div>
                @if($used !== null)<div class="mt-2 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div @class(['h-full rounded-full', 'bg-brand' => $used < 80, 'bg-saffron' => $used >= 80 && $used < 100, 'bg-danger' => $used >= 100]) style="width: {{ $used }}%"></div></div><div class="text-xs text-muted mt-1">büdcənin {{ $used }}%-i</div>@endif</div>
            <div class="card p-5" style="--i:2"><div class="text-xs text-muted">Daxilolma</div><div class="mt-1 text-2xl font-semibold font-mono text-success">{{ money($finance['income']) }}</div><div class="text-xs text-muted mt-1">Nəticə: {{ money($finance['income'] - $finance['expense']) }}</div></div>
        </div>
        <div class="grid xl:grid-cols-2 gap-6">
            <section class="card overflow-hidden">
                <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">Bank əməliyyatları</h2></header>
                @if($finance['transactions']->isEmpty())<p class="px-5 py-6 text-sm text-muted">Layihəyə bağlı əməliyyat yoxdur.</p>@else
                <div class="overflow-x-auto"><table class="table-g table-stack">
                    <thead><tr><th>Tarix</th><th>Kontragent / təyinat</th><th class="!text-right">AZN</th></tr></thead>
                    <tbody>@foreach($finance['transactions'] as $t)
                        <tr><td data-label="Tarix" class="font-mono text-xs">{{ azdate($t->transaction_date) }}</td>
                            <td data-label="Təyinat"><a href="{{ route('bank.transactions.show', $t) }}" class="hover:text-brand-ink">{{ $t->counterparty?->name ?? $t->purpose ?? '—' }}</a></td>
                            <td data-label="AZN" class="num {{ $t->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ $t->direction === 'in' ? '+' : '−' }}{{ money($t->amount_azn) }}</td></tr>
                    @endforeach</tbody></table></div>@endif
            </section>
            <section class="card overflow-hidden">
                <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">Logistika xərcləri</h2></header>
                @if($finance['costs']->isEmpty())<p class="px-5 py-6 text-sm text-muted">Logistika xərci yoxdur.</p>@else
                <div class="overflow-x-auto"><table class="table-g table-stack">
                    <thead><tr><th>Yük</th><th>Növ</th><th class="!text-right">Məbləğ</th><th class="!text-right">AZN</th></tr></thead>
                    <tbody>@foreach($finance['costs'] as $c)
                        <tr><td data-label="Yük"><a href="{{ route('shipments.show', $c->shipment) }}" class="font-mono text-xs hover:text-brand-ink">{{ $c->shipment->number }}</a></td>
                            <td data-label="Növ">{{ config('glaust.cost_types.'.$c->cost_type) }}</td>
                            <td data-label="Məbləğ" class="num">{{ money($c->amount, $c->currency) }}</td><td data-label="AZN" class="num">{{ money($c->amount_azn) }}</td></tr>
                    @endforeach</tbody></table></div>@endif
            </section>
        </div>

    @else
        <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
            @include('partials.history', ['history' => $history])
            @include('partials.attachments', ['model' => $project, 'type' => 'project', 'ability' => 'projects.update'])
        </div>
        @can('projects.delete')
            <div class="mt-6 flex justify-end">
                <x-delete-form :action="route('projects.destroy', $project)" label="Layihəni sil" button="btn btn-ghost text-danger hover:!bg-danger-soft" :message="'Layihə '.$project->code.' silinəcək (tapşırıqlar arxivdə qalır).'"/>
            </div>
        @endcan
    @endif
</x-layouts.app>
