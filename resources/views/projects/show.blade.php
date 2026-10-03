<x-layouts.app :title="$project->name" :wide="$tab === 'board'">
    @php $progress = $stats['total'] ? (int) round($stats['done'] / $stats['total'] * 100) : 0; @endphp
    <x-page-header :title="$project->name" :back="route('projects.index')">
        <x-slot:actions>
            @can('projects.create')
                <a href="{{ route('tasks.create', ['project_id' => $project->id]) }}" class="btn btn-secondary"><x-icon name="plus" class="size-4"/> Tapşırıq</a>
            @endcan
            @can('projects.update')
                <a href="{{ route('projects.edit', $project) }}" class="btn btn-primary"><x-icon name="pencil" class="size-4"/> Redaktə</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-5">
        <span class="badge badge-slate font-mono">{{ $project->code }}</span>
        <x-status group="project" :value="$project->status"/>
        <x-status group="priority" :value="$project->priority" :dot="false"/>
        @if($project->counterparty)<a href="{{ route('counterparties.show', $project->counterparty) }}" class="badge badge-teal">{{ $project->counterparty->name }}</a>@endif
        @if($project->supplier)<a href="{{ route('counterparties.show', $project->supplier) }}" class="badge badge-amber">{{ $project->supplier->name }}</a>@endif
        @if($project->isOverdue())<span class="badge badge-rose">Müddəti keçib</span>@endif
    </div>

    <nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="Layihə bölmələri">
        @foreach(['overview' => 'Ümumi', 'deals' => 'Tədarüklər ('.$deals->count().')', 'board' => 'Tapşırıqlar ('.$stats['total'].')', 'finance' => 'Maliyyə', 'files' => 'Fayllar və tarixçə'] as $key => $label)
            @if($key !== 'finance' || $finance)
                <a href="{{ route('projects.show', [$project, 'tab' => $key]) }}" @class(['tab-link', 'is-active' => $tab === $key])>{{ $label }}</a>
            @endif
        @endforeach
    </nav>

    @if($tab === 'overview')
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
            <div class="card p-5" style="--i:0">
                <div class="text-xs text-muted">İrəliləyiş</div>
                <div class="mt-1 text-2xl font-semibold font-mono">{{ $progress }}%</div>
                <div class="mt-2 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full bg-brand rounded-full" style="width: {{ $progress }}%"></div></div>
            </div>
            <div class="card p-5" style="--i:1"><div class="text-xs text-muted">Tapşırıqlar</div><div class="mt-1 text-2xl font-semibold font-mono">{{ $stats['done'] }}<span class="text-faint">/{{ $stats['total'] }}</span></div><div class="text-xs text-muted mt-1">tamamlanıb</div></div>
            <div class="card p-5" style="--i:2"><div class="text-xs text-muted">Gecikmiş</div><div @class(['mt-1 text-2xl font-semibold font-mono', 'text-danger' => $stats['overdue']])>{{ $stats['overdue'] }}</div><div class="text-xs text-muted mt-1">tapşırıq</div></div>
            <div class="card p-5" style="--i:3"><div class="text-xs text-muted">Sərf olunan vaxt</div><div class="mt-1 text-2xl font-semibold font-mono">{{ num($stats['hours'], 1) }}</div><div class="text-xs text-muted mt-1">saat</div></div>
        </div>

        @if(auth()->user()->can('contracts.view'))
            <div class="mb-6">@include('projects._contracts')</div>
        @endif

        <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
            <div class="space-y-6 min-w-0">
                @if($project->description)
                    <section class="card p-6"><h2 class="text-sm font-semibold mb-2">Təsvir</h2><p class="text-sm text-ink-2 whitespace-pre-line leading-relaxed">{{ $project->description }}</p></section>
                @endif

                <section class="card overflow-hidden">
                    <header class="flex items-center justify-between px-5 h-14 border-b border-line"><h2 class="text-sm font-semibold">Mərhələlər</h2></header>
                    @php
                        $from = $project->start_date ?? $project->milestones->min('due_date') ?? today();
                        $to = $project->end_date ?? $project->milestones->max('due_date') ?? today()->addMonth();
                        $span = max(1, $from->diffInDays($to));
                    @endphp
                    @forelse($project->milestones as $m)
                        @php $pct = $m->due_date ? max(0, min(100, $from->diffInDays($m->due_date, false) / $span * 100)) : null; $late = $m->due_date && ! $m->completed_at && $m->due_date->lt(today()); @endphp
                        <div class="px-5 py-3.5 border-b border-line last:border-0 flex items-center gap-4">
                            @can('projects.update')
                                <form method="POST" action="{{ route('projects.milestones.update', [$project, $m]) }}">@csrf @method('PUT')
                                    <button @class(['grid place-items-center size-6 rounded-full border-2 transition-colors', 'bg-success border-success text-white' => $m->completed_at, 'border-line-strong text-transparent hover:border-brand hover:text-brand' => ! $m->completed_at]) aria-label="Tamamlandı kimi işarələ"><x-icon name="check" class="size-3.5" :stroke="3"/></button>
                                </form>
                            @endcan
                            <div class="min-w-0 flex-1">
                                <div @class(['text-sm font-medium', 'line-through text-muted' => $m->completed_at])>{{ $m->name }}</div>
                                <div class="text-xs text-muted">{{ $m->done_tasks_count }}/{{ $m->tasks_count }} tapşırıq · <span @class(['font-mono', 'text-danger' => $late])>{{ azdate($m->due_date) }}</span></div>
                                @if($pct !== null)
                                    <div class="relative mt-2 h-1 rounded-full bg-surface-2"><span class="absolute top-1/2 -translate-y-1/2 size-2.5 rounded-full {{ $m->completed_at ? 'bg-success' : ($late ? 'bg-danger' : 'bg-brand') }}" style="left: calc({{ $pct }}% - 5px)"></span></div>
                                @endif
                            </div>
                            @can('projects.update')
                                <x-delete-form :action="route('projects.milestones.destroy', [$project, $m])" label="" message="Mərhələ silinsin?"/>
                            @endcan
                        </div>
                    @empty
                        <p class="px-5 py-5 text-sm text-muted">Mərhələ yoxdur.</p>
                    @endforelse
                    @can('projects.update')
                        <form method="POST" action="{{ route('projects.milestones.store', $project) }}" class="flex flex-col sm:flex-row gap-2 p-4 border-t border-line bg-surface-2/60">
                            @csrf
                            <input name="name" class="input flex-1" placeholder="Yeni mərhələ" required aria-label="Mərhələnin adı">
                            <input type="date" name="due_date" class="input sm:!w-44 font-mono" aria-label="Son tarix">
                            <button class="btn btn-secondary"><x-icon name="plus" class="size-4"/> Əlavə et</button>
                        </form>
                    @endcan
                </section>

                <section class="card overflow-hidden">
                    <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                        <h2 class="text-sm font-semibold">Yaxın tapşırıqlar</h2>
                        <a href="{{ route('projects.show', [$project, 'tab' => 'board']) }}" class="text-xs font-medium text-brand-ink hover:underline">Lövhə</a>
                    </header>
                    @php $upcoming = $tasks->where('status', '!=', 'done')->sortBy(fn ($t) => $t->due_date?->timestamp ?? PHP_INT_MAX)->take(6); @endphp
                    @forelse($upcoming as $t)
                        <a href="{{ route('tasks.show', $t) }}" class="flex items-center gap-3 px-5 py-3 border-b border-line last:border-0 hover:bg-surface-2">
                            <x-status group="task" :value="$t->status" :dot="false" class="!h-5 !text-[11px] w-24 justify-center"/>
                            <span class="flex-1 min-w-0 truncate text-sm">{{ $t->title }}</span>
                            <x-avatar :user="$t->assignee" size="xs"/>
                            <span @class(['text-xs font-mono w-20 text-right', 'text-danger' => $t->isOverdue(), 'text-muted' => ! $t->isOverdue()])>{{ azdate($t->due_date) }}</span>
                        </a>
                    @empty
                        <p class="px-5 py-5 text-sm text-muted">Açıq tapşırıq yoxdur.</p>
                    @endforelse
                </section>
            </div>

            <aside class="space-y-6 lg:sticky lg:top-24">
                <section class="card p-5 text-sm space-y-3">
                    <div class="flex justify-between gap-3"><span class="text-muted">Menecer</span><span class="inline-flex items-center gap-2">@if($project->manager)<x-avatar :user="$project->manager" size="xs"/>@endif{{ $project->manager?->name ?? '—' }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-muted">Başlama</span><span class="font-mono">{{ azdate($project->start_date) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-muted">Bitmə</span><span @class(['font-mono', 'text-danger' => $project->isOverdue()])>{{ azdate($project->end_date) }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-muted">Büdcə</span><span class="font-mono">{{ $project->budget > 0 ? money($project->budget, $project->currency) : '—' }}</span></div>
                </section>
                <section class="card p-5">
                    <h2 class="text-sm font-semibold mb-3">Komanda <span class="text-muted font-mono font-normal">{{ $project->members->count() }}</span></h2>
                    <ul class="space-y-2.5">
                        @forelse($project->members as $m)
                            <li class="flex items-center gap-3"><x-avatar :user="$m" size="sm"/><div class="min-w-0"><div class="text-sm font-medium truncate">{{ $m->name }}</div><div class="text-xs text-muted truncate">{{ $m->position }}</div></div></li>
                        @empty
                            <li class="text-sm text-muted">Komanda təyin edilməyib.</li>
                        @endforelse
                    </ul>
                </section>
            </aside>
        </div>

    @elseif($tab === 'deals')
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <p class="text-sm text-muted max-w-2xl">Tədarük — məhsulun satıcıdan alınıb alıcıya satıldığı bir partiyadır: hər iki tərəflə müqavilələr, satıcının fakturaları və alıcıya faktura bir yerdə.</p>
            @can('projects.create')
                <a href="{{ route('deals.create', $project) }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Yeni tədarük</a>
            @endcan
        </div>
        @if($deals->isEmpty())
            <div class="card"><x-empty icon="package" title="Hələ tədarük yoxdur" text="Yeni tədarük yaradın: tərəflər və müqavilələr layihədən avtomatik gələcək, sonra satıcının fakturasını Excel-dən import edəcəksiniz.">
                @can('projects.create')<a href="{{ route('deals.create', $project) }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Tədarük yarat</a>@endcan
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
                        <div class="mt-4 pt-4 border-t border-line flex justify-between text-sm">
                            <span class="text-muted">{{ $d->invoices_count }} faktura</span>
                            <span class="font-mono">{{ $d->supplier_total_azn ? money($d->supplier_total_azn) : '—' }}</span>
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
