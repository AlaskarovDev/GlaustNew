<x-layouts.app title="Tapşırıqlar" :wide="in_array($view, ['board', 'gantt'])">
    <x-page-header title="Tapşırıqlar" icon="kanban" subtitle="Bütün layihələr üzrə tapşırıqlar">
        <x-slot:actions>
            @can('projects.create')
                <a href="{{ route('tasks.create', array_filter(['project_id' => request('project_id')])) }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Yeni tapşırıq</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <nav class="flex gap-6 border-b border-line mb-5 overflow-x-auto" aria-label="Görünüş">
        @foreach(['board' => ['kanban', 'Kanban'], 'list' => ['list', 'Siyahı'], 'calendar' => ['calendar', 'Təqvim'], 'gantt' => ['gantt', 'Gantt']] as $key => [$icon, $label])
            <a href="{{ request()->fullUrlWithQuery(['view' => $key, 'page' => null]) }}" @class(['tab-link inline-flex items-center gap-1.5', 'is-active' => $view === $key])><x-icon :name="$icon" class="size-4"/> {{ $label }}</a>
        @endforeach
    </nav>

    <div class="card overflow-hidden mb-5">
        <x-filter-bar :table="$table" export-route="tasks.export" export-ability="projects.export" placeholder="Tapşırıq, layihə…"/>
    </div>

    @if($view === 'board')
        @include('tasks._board', ['columns' => $columns, 'project' => null])

    @elseif($view === 'list')
        <div class="card overflow-hidden">
            @if($items->isEmpty())
                <x-empty icon="list-checks" title="Tapşırıq tapılmadı"/>
            @else
                <div class="overflow-x-auto">
                    <table class="table-g table-stack">
                        <thead><tr><x-th :table="$table" sort="title">Tapşırıq</x-th><th>Layihə</th><th>Məsul</th><th>Prioritet</th><x-th :table="$table" sort="due">Son tarix</x-th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach($items as $t)
                            <tr>
                                <td data-label="Tapşırıq"><a href="{{ route('tasks.show', $t) }}" class="font-medium text-ink hover:text-brand-ink">{{ $t->title }}</a>
                                    @if($t->checklist_count)<span class="ml-2 text-xs text-muted font-mono">{{ $t->checklist_done_count }}/{{ $t->checklist_count }}</span>@endif</td>
                                <td data-label="Layihə" class="text-xs">{{ $t->project?->code }}</td>
                                <td data-label="Məsul"><span class="inline-flex items-center gap-2"><x-avatar :user="$t->assignee" size="xs"/><span class="text-xs">{{ $t->assignee?->name ?? '—' }}</span></span></td>
                                <td data-label="Prioritet"><x-status group="priority" :value="$t->priority" :dot="false"/></td>
                                <td data-label="Son tarix" @class(['font-mono text-xs', 'text-danger font-medium' => $t->isOverdue()])>{{ azdate($t->due_date) }}</td>
                                <td data-label="Status"><x-status group="task" :value="$t->status"/></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $items->links() }}
            @endif
        </div>

    @elseif($view === 'calendar')
        @php
            $start = $month->copy()->startOfMonth()->startOfWeek(); $end = $month->copy()->endOfMonth()->endOfWeek();
            $prev = $month->copy()->subMonth()->format('Y-m'); $next = $month->copy()->addMonth()->format('Y-m');
        @endphp
        <div class="card overflow-hidden">
            <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                <a href="{{ request()->fullUrlWithQuery(['month' => $prev]) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="Əvvəlki ay"><x-icon name="chevron-left" class="size-4"/></a>
                <h2 class="text-base font-semibold">{{ az_month($month->month) }} {{ $month->year }}</h2>
                <a href="{{ request()->fullUrlWithQuery(['month' => $next]) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="Növbəti ay"><x-icon name="chevron-right" class="size-4"/></a>
            </header>
            <div class="hidden md:grid grid-cols-7 border-b border-line bg-surface-2 text-xs font-medium text-muted">
                @foreach(['B.e', 'Ç.a', 'Ç', 'C.a', 'C', 'Ş', 'B'] as $d)<div class="px-3 py-2">{{ $d }}</div>@endforeach
            </div>
            <div class="grid md:grid-cols-7">
                @for($d = $start->copy(); $d->lte($end); $d->addDay())
                    @php $list = $byDay[$d->format('Y-m-d')] ?? collect(); $inMonth = $d->month === $month->month; @endphp
                    <div @class(['md:min-h-[118px] border-b md:border-r border-line p-2', 'bg-surface-2/50' => ! $inMonth, 'hidden md:block' => ! $inMonth && $list->isEmpty()])>
                        <div class="flex items-center justify-between mb-1">
                            <span @class(['text-xs font-mono grid place-items-center size-6 rounded-full', 'bg-brand text-white font-semibold' => $d->isToday(), 'text-faint' => ! $inMonth, 'text-ink-2' => $inMonth && ! $d->isToday()])>{{ $d->day }}</span>
                            <span class="md:hidden text-xs text-muted">{{ az_weekday($d) }}</span>
                        </div>
                        <div class="space-y-1">
                            @foreach($list->take(4) as $t)
                                <a href="{{ route('tasks.show', $t) }}" @class(['block truncate rounded-md px-1.5 py-1 text-[11px] font-medium', 'badge-'.status_color('priority', $t->priority), 'line-through opacity-60' => $t->status === 'done']) title="{{ $t->title }}">{{ $t->title }}</a>
                            @endforeach
                            @if($list->count() > 4)<div class="text-[11px] text-muted px-1">+{{ $list->count() - 4 }} daha</div>@endif
                        </div>
                    </div>
                @endfor
            </div>
        </div>

    @else
        @php
            $min = $gantt->map(fn ($t) => $t->start_date ?? $t->due_date)->min() ?? today();
            $max = $gantt->max('due_date') ?? today()->addMonth();
            $min = $min->copy()->startOfWeek(); $max = $max->copy()->endOfWeek();
            $days = max(7, $min->diffInDays($max) + 1);
            $todayPct = $min->diffInDays(today(), false) / $days * 100;
        @endphp
        <div class="card overflow-hidden">
            @if($gantt->isEmpty())
                <x-empty icon="gantt" title="Son tarixi olan tapşırıq yoxdur"/>
            @else
                <div class="overflow-x-auto">
                    <div class="min-w-[900px]">
                        <div class="grid grid-cols-[260px_1fr] border-b border-line bg-surface-2 text-xs text-muted">
                            <div class="px-4 py-2 font-medium">Tapşırıq</div>
                            <div class="relative h-8">
                                @for($w = $min->copy(); $w->lt($max); $w->addWeek())
                                    <span class="absolute top-2 font-mono" style="left: {{ $min->diffInDays($w) / $days * 100 }}%">{{ $w->format('d.m') }}</span>
                                @endfor
                            </div>
                        </div>
                        @foreach($gantt as $t)
                            @php
                                $s = $t->start_date && $t->start_date->lte($t->due_date) ? $t->start_date : $t->due_date;
                                $left = $min->diffInDays($s) / $days * 100;
                                $width = max(1.2, ($s->diffInDays($t->due_date) + 1) / $days * 100);
                                $color = ['todo' => 'bg-slate-400', 'in_progress' => 'bg-blue-500', 'review' => 'bg-violet-500', 'done' => 'bg-success'][$t->status];
                            @endphp
                            <div class="grid grid-cols-[260px_1fr] border-b border-line last:border-0 hover:bg-surface-2/60">
                                <a href="{{ route('tasks.show', $t) }}" class="px-4 py-2.5 text-sm truncate hover:text-brand-ink">{{ $t->title }}<span class="block text-[11px] text-muted">{{ $t->project?->code }} · {{ $t->assignee?->name ?? 'təyin edilməyib' }}</span></a>
                                <div class="relative">
                                    @if($todayPct >= 0 && $todayPct <= 100)<span class="absolute inset-y-0 w-px bg-danger/50" style="left: {{ $todayPct }}%"></span>@endif
                                    <span class="absolute top-1/2 -translate-y-1/2 h-5 rounded-md {{ $color }} {{ $t->isOverdue() ? 'ring-2 ring-danger/60' : '' }} rise" style="left: {{ $left }}%; width: {{ $width }}%" title="{{ azdate($s) }} — {{ azdate($t->due_date) }}"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="px-4 py-3 border-t border-line text-xs text-muted">Qırmızı xətt — bu gün. İlk 80 tapşırıq göstərilir; filtrlərlə daraldın.</p>
            @endif
        </div>
    @endif
</x-layouts.app>
