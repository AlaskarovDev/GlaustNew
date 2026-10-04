<x-layouts.app :title="$task->title">
    @php
        $minutes = $task->timeEntries->sum('minutes');
        $doneItems = $task->checklist->where('is_done', true)->count();
    @endphp
    <x-page-header :title="$task->title" :back="$task->project ? route('projects.show', [$task->project, 'tab' => 'board']) : route('tasks.index')">
        <x-slot:actions>
            @if($canEdit && $task->status !== 'done')
                <form method="POST" action="{{ route('tasks.move', $task) }}" x-data @submit.prevent="glaustApi(@js(route('tasks.move', $task)), { method: 'POST', body: { status: 'done' } }).then(d => { if (d.ok) { toast('success', {{ \Illuminate\Support\Js::from(__('Tapşırıq tamamlandı')) }}); setTimeout(() => location.reload(), 400) } else toast('error', d.message) }).catch(e => toast('error', e.message))">
                    @csrf
                    <button class="btn btn-secondary"><x-icon name="check-circle" class="size-4 text-success"/> {{ __('Tamamla') }}</button>
                </form>
            @endif
            @can('projects.update')
                <a href="{{ route('tasks.edit', $task) }}" class="btn btn-primary"><x-icon name="pencil" class="size-4"/> {{ __('Redaktə') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
        <x-status group="task" :value="$task->status"/>
        <x-status group="priority" :value="$task->priority" :dot="false"/>
        @if($task->project)<a href="{{ route('projects.show', $task->project) }}" class="badge badge-teal">{{ $task->project->code }} · {{ $task->project->name }}</a>@endif
        @if($task->milestone)<span class="badge badge-violet"><x-icon name="flag" class="size-3"/> {{ $task->milestone->name }}</span>@endif
        @if($task->isOverdue())<span class="badge badge-rose">{{ (int) $task->due_date->diffInDays(today()) }} {{ __('gün gecikir') }}</span>@endif
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <h2 class="text-sm font-semibold mb-2">{{ __('Təsvir') }}</h2>
                @if($task->description)
                    <p class="text-sm text-ink-2 whitespace-pre-line leading-relaxed">{{ $task->description }}</p>
                @else
                    <p class="text-sm text-muted">{{ __('Təsvir yoxdur.') }}</p>
                @endif
            </section>

            <section class="card p-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold">{{ __('Yoxlama siyahısı') }}</h2>
                    @if($task->checklist->count())<span class="text-xs font-mono text-muted">{{ $doneItems }}/{{ $task->checklist->count() }}</span>@endif
                </div>
                @if($task->checklist->count())
                    <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden mb-4"><div class="h-full bg-success rounded-full transition-all duration-500" style="width: {{ round($doneItems / $task->checklist->count() * 100) }}%"></div></div>
                @endif
                <ul class="space-y-1">
                    @foreach($task->checklist as $item)
                        <li class="flex items-center gap-3 group py-1">
                            @if($canEdit)
                                <form method="POST" action="{{ route('tasks.checklist.toggle', [$task, $item]) }}">@csrf
                                    <button @class(['grid place-items-center size-5 rounded border-2 transition-colors', 'bg-success border-success text-white' => $item->is_done, 'border-line-strong text-transparent hover:border-brand' => ! $item->is_done]) aria-label="{{ $item->is_done ? 'Geri al' : 'Tamamla' }}: {{ $item->title }}"><x-icon name="check" class="size-3" :stroke="3"/></button>
                                </form>
                            @else
                                <span @class(['size-5 rounded border-2', 'bg-success border-success' => $item->is_done, 'border-line-strong' => ! $item->is_done])></span>
                            @endif
                            <span @class(['flex-1 text-sm', 'line-through text-muted' => $item->is_done])>{{ $item->title }}</span>
                            @if($canEdit)
                                <form method="POST" action="{{ route('tasks.checklist.destroy', [$task, $item]) }}" class="opacity-0 group-hover:opacity-100 focus-within:opacity-100">@csrf @method('DELETE')
                                    <button class="text-faint hover:text-danger" aria-label="{{ __('Sil') }}"><x-icon name="x" class="size-4"/></button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($canEdit)
                    <form method="POST" action="{{ route('tasks.checklist.store', $task) }}" class="mt-3 flex gap-2">@csrf
                        <input name="title" class="input" placeholder="{{ __('Yeni bənd…') }}" required aria-label="{{ __('Yeni bənd') }}">
                        <button class="btn btn-secondary"><x-icon name="plus" class="size-4"/></button>
                    </form>
                @endif
            </section>

            <section class="card p-6">
                <h2 class="text-sm font-semibold mb-4">{{ __('Şərhlər') }} <span class="text-muted font-mono font-normal">{{ $task->comments->count() }}</span></h2>
                <form method="POST" action="{{ route('tasks.comments.store', $task) }}" class="flex gap-3 mb-5">@csrf
                    <x-avatar :user="auth()->user()" size="sm" class="mt-1"/>
                    <div class="flex-1 space-y-2">
                        <textarea name="body" rows="2" class="input" placeholder="{{ __('Şərh yazın…') }}" required aria-label="{{ __('Şərh') }}"></textarea>
                        <div class="flex justify-end"><button class="btn btn-primary btn-sm"><x-icon name="send" class="size-4"/> {{ __('Göndər') }}</button></div>
                    </div>
                </form>
                <ol class="space-y-4">
                    @foreach($task->comments as $c)
                        <li class="flex gap-3">
                            <x-avatar :user="$c->user" size="sm"/>
                            <div class="flex-1 min-w-0 rounded-xl bg-surface-2 px-4 py-3">
                                <div class="flex items-baseline gap-2"><span class="text-sm font-medium">{{ $c->user?->name ?? __('Silinmiş istifadəçi') }}</span><span class="text-xs text-faint">{{ $c->created_at->diffForHumans() }}</span></div>
                                <p class="mt-1 text-sm text-ink-2 whitespace-pre-line break-words">{{ $c->body }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>

            @include('partials.history', ['history' => $history])
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-5 text-sm space-y-3">
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Məsul') }}</span><span class="inline-flex items-center gap-2"><x-avatar :user="$task->assignee" size="xs"/>{{ $task->assignee?->name ?? '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Yaradan') }}</span><span>{{ $task->creator?->name ?? '—' }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Başlama') }}</span><span class="font-mono">{{ azdate($task->start_date) }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Son tarix') }}</span><span @class(['font-mono', 'text-danger' => $task->isOverdue()])>{{ azdate($task->due_date) }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Plan / fakt') }}</span><span class="font-mono">{{ $task->estimated_hours ? num($task->estimated_hours, 1) : '—' }} / {{ num($minutes / 60, 1) }} saat</span></div>
                @if($task->completed_at)<div class="flex justify-between gap-3"><span class="text-muted">{{ __('Tamamlanıb') }}</span><span class="font-mono">{{ azdate($task->completed_at, true) }}</span></div>@endif
            </section>

            <section class="card p-5">
                <h2 class="text-sm font-semibold mb-3 flex items-center gap-2"><x-icon name="timer" class="size-4 text-muted"/> {{ __('Vaxt qeydi') }}</h2>
                @if($canEdit)
                    <form method="POST" action="{{ route('tasks.time.store', $task) }}" class="grid grid-cols-[1fr_90px] gap-2 mb-3">@csrf
                        <input type="date" name="work_date" value="{{ today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" class="input font-mono" aria-label="{{ __('Tarix') }}" required>
                        <input name="hours" class="input font-mono text-right" placeholder="{{ __('saat') }}" inputmode="decimal" aria-label="{{ __('Saat') }}" required>
                        <input name="note" class="input col-span-2" placeholder="{{ __('Qeyd (istəyə bağlı)') }}" aria-label="{{ __('Qeyd') }}">
                        <button class="btn btn-secondary col-span-2"><x-icon name="plus" class="size-4"/> {{ __('Qeyd et') }}</button>
                    </form>
                    @error('hours')<p class="field-error mb-2">{{ $message }}</p>@enderror
                @endif
                <ul class="space-y-2 text-xs">
                    @forelse($task->timeEntries->take(8) as $e)
                        <li class="flex justify-between gap-2"><span class="text-muted truncate">{{ azdate($e->work_date) }} · {{ $e->user?->name }}{{ $e->note ? ' · '.$e->note : '' }}</span><span class="font-mono shrink-0">{{ num($e->minutes / 60, 1) }} s</span></li>
                    @empty
                        <li class="text-muted">{{ __('Vaxt qeyd edilməyib.') }}</li>
                    @endforelse
                </ul>
            </section>

            @include('partials.attachments', ['model' => $task, 'type' => 'task', 'ability' => 'projects.view'])

            @can('projects.delete')
                <x-delete-form :action="route('tasks.destroy', $task)" :label="__('Tapşırığı sil')" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="__('Tapşırıq birdəfəlik silinəcək.')"/>
            @endcan
        </aside>
    </div>
</x-layouts.app>
