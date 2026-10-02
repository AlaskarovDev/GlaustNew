<x-layouts.app title="Layihələr">
    <x-page-header title="Layihələr" icon="folder" subtitle="Layihələr, mərhələlər, komanda və büdcə">
        <x-slot:actions>
            <div class="flex p-1 rounded-[10px] bg-surface border border-line" role="group" aria-label="Görünüş">
                <a href="{{ request()->fullUrlWithQuery(['view' => 'grid', 'page' => null]) }}" @class(['btn btn-sm !h-8', 'bg-surface-2 text-ink' => $view === 'grid', 'btn-ghost text-muted' => $view !== 'grid']) aria-label="Kartlar"><x-icon name="dashboard" class="size-4"/></a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'list', 'page' => null]) }}" @class(['btn btn-sm !h-8', 'bg-surface-2 text-ink' => $view === 'list', 'btn-ghost text-muted' => $view !== 'list']) aria-label="Siyahı"><x-icon name="list" class="size-4"/></a>
            </div>
            <a href="{{ route('tasks.index') }}" class="btn btn-secondary"><x-icon name="kanban" class="size-4"/> Tapşırıqlar</a>
            @can('projects.create')
                <a href="{{ route('projects.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Yeni layihə</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <nav class="flex gap-6 border-b border-line mb-5 overflow-x-auto" aria-label="Status">
        <a href="{{ route('projects.index', ['view' => $view]) }}" @class(['tab-link', 'is-active' => ! request('status') && ! request('mine')])>Hamısı <span class="text-xs font-mono text-faint ml-1">{{ $counts->sum() }}</span></a>
        <a href="{{ route('projects.index', ['view' => $view, 'mine' => 1]) }}" @class(['tab-link', 'is-active' => request('mine')])>Mənim</a>
        @foreach(status_options('project') as $key => $label)
            <a href="{{ route('projects.index', ['view' => $view, 'status' => $key]) }}" @class(['tab-link', 'is-active' => request('status') === $key])>{{ $label }} <span class="text-xs font-mono text-faint ml-1">{{ $counts[$key] ?? 0 }}</span></a>
        @endforeach
    </nav>

    <div class="card overflow-hidden mb-5">
        <x-filter-bar :table="$table" export-route="projects.export" export-ability="projects.export" placeholder="Kod, ad, müştəri…"/>
    </div>

    @if($items->isEmpty())
        <div class="card">
            <x-empty icon="folder" :title="$table->hasActiveFilters() ? 'Heç nə tapılmadı' : 'Hələ layihə yoxdur'" text="Layihə yaradın, komandanı və mərhələləri təyin edin.">
                @can('projects.create')<a href="{{ route('projects.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Layihə yarat</a>@endcan
            </x-empty>
        </div>
    @elseif($view === 'grid')
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5 stagger">
            @foreach($items as $p)
                @php $progress = $p->progress(); @endphp
                <a href="{{ route('projects.show', $p) }}" class="card card-hover p-5 flex flex-col group" style="--i:{{ $loop->index % 12 }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-xs font-mono text-muted">{{ $p->code }}</div>
                            <h3 class="mt-1 font-semibold leading-snug group-hover:text-brand-ink line-clamp-2">{{ $p->name }}</h3>
                        </div>
                        <x-status group="project" :value="$p->status"/>
                    </div>
                    <div class="mt-1 text-sm text-muted truncate">{{ $p->counterparty?->name ?? 'Daxili layihə' }}</div>

                    <div class="mt-5">
                        <div class="flex items-center justify-between text-xs mb-1.5">
                            <span class="text-muted">{{ $p->done_tasks_count }}/{{ $p->tasks_count }} tapşırıq</span>
                            <span class="font-mono font-medium">{{ $progress }}%</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full rounded-full bg-brand transition-all duration-700" style="width: {{ $progress }}%"></div></div>
                    </div>

                    <div class="mt-5 pt-4 border-t border-line flex items-center gap-3">
                        <div class="flex -space-x-2">
                            @foreach($p->members->take(4) as $m)<x-avatar :user="$m" size="sm" class="ring-2 ring-surface"/>@endforeach
                            @if($p->members->count() > 4)<span class="grid place-items-center size-7 rounded-full bg-surface-2 ring-2 ring-surface text-[10px] font-semibold text-muted">+{{ $p->members->count() - 4 }}</span>@endif
                        </div>
                        <div class="ml-auto text-right">
                            @if($p->end_date)
                                <div @class(['text-xs font-mono', 'text-danger font-medium' => $p->isOverdue(), 'text-muted' => ! $p->isOverdue()])>{{ azdate($p->end_date) }}</div>
                            @endif
                            @if($p->overdue_tasks_count)<div class="text-[11px] text-danger">{{ $p->overdue_tasks_count }} gecikmiş tapşırıq</div>@endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-5 card">{{ $items->links() }}</div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr>
                        <x-th :table="$table" sort="code">Kod</x-th><x-th :table="$table" sort="name">Ad</x-th><th>Menecer</th>
                        <th>İrəliləyiş</th><x-th :table="$table" sort="end">Bitmə</x-th><x-th :table="$table" sort="budget" num>Büdcə</x-th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    @foreach($items as $p)
                        <tr>
                            <td data-label="Kod" class="font-mono text-xs">{{ $p->code }}</td>
                            <td data-label="Ad"><a href="{{ route('projects.show', $p) }}" class="font-medium text-ink hover:text-brand-ink">{{ $p->name }}</a><div class="text-xs text-muted">{{ $p->counterparty?->name }}</div></td>
                            <td data-label="Menecer"><span class="inline-flex items-center gap-2"><x-avatar :user="$p->manager" size="xs"/>{{ $p->manager?->name ?? '—' }}</span></td>
                            <td data-label="İrəliləyiş"><span class="inline-flex items-center gap-2"><span class="w-20 h-1.5 rounded-full bg-surface-2 overflow-hidden"><span class="block h-full bg-brand" style="width: {{ $p->progress() }}%"></span></span><span class="font-mono text-xs">{{ $p->progress() }}%</span></span></td>
                            <td data-label="Bitmə" @class(['font-mono text-xs', 'text-danger' => $p->isOverdue()])>{{ azdate($p->end_date) }}</td>
                            <td data-label="Büdcə" class="num">{{ $p->budget > 0 ? money($p->budget, $p->currency) : '—' }}</td>
                            <td data-label="Status"><x-status group="project" :value="$p->status"/></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        </div>
    @endif
</x-layouts.app>
