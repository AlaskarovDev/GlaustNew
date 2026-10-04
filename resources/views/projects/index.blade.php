<x-layouts.app title="Layihələr">
    <x-page-header title="Layihələr" icon="folder" subtitle="Hər layihə: tərəflər, məsul şəxs, sövdələşmələr və proqnoz mənfəət">
        <x-slot:actions>
            <div class="flex p-1 rounded-[10px] bg-surface border border-line" role="group" aria-label="Görünüş">
                <a href="{{ request()->fullUrlWithQuery(['view' => 'grid', 'page' => null]) }}" @class(['btn btn-sm !h-8', 'bg-surface-2 text-ink' => $view === 'grid', 'btn-ghost text-muted' => $view !== 'grid']) aria-label="Kartlar"><x-icon name="dashboard" class="size-4"/></a>
                <a href="{{ request()->fullUrlWithQuery(['view' => 'list', 'page' => null]) }}" @class(['btn btn-sm !h-8', 'bg-surface-2 text-ink' => $view === 'list', 'btn-ghost text-muted' => $view !== 'list']) aria-label="Siyahı"><x-icon name="list" class="size-4"/></a>
            </div>
            @can('projects.create')
                <a href="{{ route('projects.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Yeni layihə</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-3 mb-5">
        <div class="flex p-1 rounded-[10px] bg-surface-2" role="group" aria-label="Layihələr">
            <a href="{{ route('projects.index', ['view' => $view]) }}" @class(['btn btn-sm !h-8', 'bg-surface text-ink shadow-[var(--shadow-card)]' => ! request('mine'), 'btn-ghost text-muted' => request('mine')])>Hamısı</a>
            <a href="{{ route('projects.index', ['view' => $view, 'mine' => 1]) }}" @class(['btn btn-sm !h-8', 'bg-surface text-ink shadow-[var(--shadow-card)]' => request('mine'), 'btn-ghost text-muted' => ! request('mine')])>Mənim</a>
        </div>
    </div>

    <div class="card overflow-hidden mb-5">
        <x-filter-bar :table="$table" export-route="projects.export" export-ability="projects.export" placeholder="Kod, ad, şirkət…"/>
    </div>

    @if($items->isEmpty())
        <div class="card">
            <x-empty icon="folder" :title="$table->hasActiveFilters() ? 'Heç nə tapılmadı' : 'Hələ layihə yoxdur'" text="Layihə yaradın: tərəfləri və müqavilələri seçin, sonra sövdələşmələr əlavə edin.">
                @can('projects.create')<a href="{{ route('projects.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Layihə yarat</a>@endcan
            </x-empty>
        </div>
    @elseif($view === 'grid')
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-5 stagger">
            @foreach($items as $p)
                @php $profit = \App\Support\ProjectForecast::profit($p->deals); @endphp
                <a href="{{ route('projects.show', $p) }}" class="card card-hover p-5 flex flex-col group" style="--i:{{ $loop->index % 12 }}">
                    <div class="text-xs font-mono text-muted">{{ $p->code }}</div>
                    <h3 class="mt-1 font-semibold leading-snug group-hover:text-brand-ink line-clamp-2">{{ $p->name }}</h3>

                    <div class="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-sm">
                        <div class="min-w-0"><div class="text-[11px] text-muted">Satıcı</div><div class="truncate">{{ $p->supplier?->name ?? '—' }}</div></div>
                        <x-icon name="arrow-right" class="size-4 text-faint"/>
                        <div class="min-w-0"><div class="text-[11px] text-muted">Alıcı</div><div class="truncate">{{ $p->counterparty?->name ?? '—' }}</div></div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div class="rounded-xl bg-surface-2 px-3 py-2.5">
                            <div class="text-[11px] text-muted">Sövdələşmələr</div>
                            <div class="font-mono text-lg font-semibold">{{ $p->deals_count }}</div>
                            <div class="text-[11px] {{ $p->active_deals_count ? 'text-brand-ink' : 'text-faint' }}">{{ $p->active_deals_count }} davam edir</div>
                        </div>
                        <div class="rounded-xl bg-surface-2 px-3 py-2.5" title="Təxmini: satıcı fakturalarına əlavə etdiyimiz komissiya (bugünkü CBAR ilə AZN) − sövdələşmələrə yazılan xərclər (bank komissiyası və s.)">
                            <div class="text-[11px] text-muted">Proqnoz mənfəət</div>
                            <div @class(['font-mono text-lg font-semibold', 'text-success' => $profit > 0, 'text-danger' => $profit !== null && $profit < 0, 'text-faint' => $profit === null])>{{ $profit === null ? '—' : '≈ '.money($profit) }}</div>
                            <div class="text-[11px] text-faint">{{ $profit === null ? 'komissiya hələ yoxdur' : 'bugünkü CBAR ilə' }}</div>
                        </div>
                    </div>

                    <div class="mt-auto pt-4 flex items-center gap-2 text-sm">
                        <span class="text-[11px] text-muted">Məsul:</span>
                        @if($p->manager)<x-avatar :user="$p->manager" size="xs"/><span class="truncate">{{ $p->manager->name }}</span>@else<span class="text-faint">—</span>@endif
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
                        <x-th :table="$table" sort="code">Kod</x-th><x-th :table="$table" sort="name">Ad</x-th><th>Satıcı → Alıcı</th><th>Məsul şəxs</th>
                        <th class="!text-right">Sövdələşmələr</th><th class="!text-right">Proqnoz mənfəət</th>
                    </tr></thead>
                    <tbody>
                    @foreach($items as $p)
                        @php $profit = \App\Support\ProjectForecast::profit($p->deals); @endphp
                        <tr>
                            <td data-label="Kod" class="font-mono text-xs">{{ $p->code }}</td>
                            <td data-label="Ad"><a href="{{ route('projects.show', $p) }}" class="font-medium text-ink hover:text-brand-ink">{{ $p->name }}</a></td>
                            <td data-label="Tərəflər" class="text-sm">{{ $p->supplier?->name ?? '—' }} <span class="text-faint">→</span> {{ $p->counterparty?->name ?? '—' }}</td>
                            <td data-label="Məsul"><span class="inline-flex items-center gap-2"><x-avatar :user="$p->manager" size="xs"/>{{ $p->manager?->name ?? '—' }}</span></td>
                            <td data-label="Sövdələşmələr" class="num">{{ $p->deals_count }}<div class="text-[11px] text-muted">{{ $p->active_deals_count }} davam edir</div></td>
                            <td data-label="Mənfəət" @class(['num', 'text-success' => $profit > 0, 'text-danger' => $profit !== null && $profit < 0])>{{ $profit === null ? '—' : '≈ '.money($profit) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        </div>
    @endif
</x-layouts.app>
