{{-- $columns: Collection grouped by status; $project: optional Project for "add" links --}}
@php
    $statusDot = ['todo' => 'bg-slate-400', 'in_progress' => 'bg-blue-500', 'review' => 'bg-violet-500', 'done' => 'bg-success'];
@endphp
<div x-data="kanban(@js(route('tasks.move', '__ID__')))" class="grid grid-flow-col auto-cols-[minmax(272px,1fr)] gap-4 overflow-x-auto pb-4 -mx-4 px-4 sm:mx-0 sm:px-0 snap-x">
    @foreach(config('glaust.statuses.task') as $status => [$label])
        @php $list = $columns[$status] ?? collect(); @endphp
        <section class="flex flex-col min-h-[200px] rounded-[var(--radius-card)] bg-surface-2/70 border border-line snap-start" aria-label="{{ $label }}">
            <header class="flex items-center gap-2 px-3.5 h-12 shrink-0">
                <span class="size-2 rounded-full {{ $statusDot[$status] }}"></span>
                <h3 class="text-sm font-semibold">{{ $label }}</h3>
                <span class="text-xs font-mono text-muted" data-count-for="{{ $status }}">{{ $list->count() }}</span>
                @can('projects.create')
                    <a href="{{ route('tasks.create', array_filter(['status' => $status, 'project_id' => $project?->id])) }}" class="ml-auto btn btn-ghost btn-sm btn-icon !size-7" aria-label="{{ __(':v1 sütununa tapşırıq əlavə et', ['v1' => $label]) }}"><x-icon name="plus" class="size-4"/></a>
                @endcan
            </header>
            <div class="flex-1 px-2.5 pb-2.5 space-y-2 min-h-16" data-column="{{ $status }}">
                @foreach($list as $task)
                    <article data-id="{{ $task->id }}" class="card p-3.5 cursor-grab active:cursor-grabbing hover:border-line-strong transition-colors group">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('tasks.show', $task) }}" @class(['text-sm font-medium leading-snug hover:text-brand-ink', 'line-through text-muted' => $task->status === 'done'])>{{ $task->title }}</a>
                            <x-icon name="grip" class="size-4 text-faint opacity-0 group-hover:opacity-100 shrink-0"/>
                        </div>
                        @if(! $project && $task->project)
                            <div class="mt-1 text-xs text-muted truncate">{{ $task->project->code }} · {{ $task->project->name }}</div>
                        @endif
                        <div class="mt-3 flex items-center gap-2 flex-wrap">
                            <x-status group="priority" :value="$task->priority" :dot="false" class="!h-5 !text-[11px]"/>
                            @if($task->due_date)
                                <span @class(['inline-flex items-center gap-1 text-[11px] font-mono', 'text-danger font-medium' => $task->isOverdue(), 'text-muted' => ! $task->isOverdue()])>
                                    <x-icon name="clock" class="size-3"/>{{ $task->due_date->format('d.m') }}
                                </span>
                            @endif
                            @if($task->checklist_count)
                                <span class="inline-flex items-center gap-1 text-[11px] text-muted font-mono"><x-icon name="list-checks" class="size-3"/>{{ $task->checklist_done_count }}/{{ $task->checklist_count }}</span>
                            @endif
                            @if($task->comments_count)
                                <span class="inline-flex items-center gap-1 text-[11px] text-muted font-mono"><x-icon name="message" class="size-3"/>{{ $task->comments_count }}</span>
                            @endif
                            <x-avatar :user="$task->assignee" size="xs" class="ml-auto"/>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
