<x-layouts.app :title="__('Mənim işlərim')">
    <x-page-header :title="__('Mənim işlərim')" icon="calendar-check"
                   :subtitle="az_weekday($today).', '.$today->day.' '.az_month($today->month).' · bu gün '.$doneToday.' tapşırıq tamamlanıb'">
        <x-slot:actions>
            @can('projects.create')
                <a href="{{ route('tasks.create', ['assignee_id' => auth()->id()]) }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Tapşırıq') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        <div class="space-y-5 stagger">
            @foreach($groups as $key => [$label, $tasks])
                <section class="card overflow-hidden" style="--i:{{ $loop->index }}">
                    <header class="flex items-center gap-2 px-5 h-12 border-b border-line bg-surface-2">
                        <span @class(['size-2 rounded-full', 'bg-danger' => $key === 'overdue', 'bg-saffron' => $key === 'today', 'bg-brand' => $key === 'week', 'bg-faint' => $key === 'later'])></span>
                        <h2 class="text-sm font-semibold">{{ $label }}</h2>
                        <span class="text-xs font-mono text-muted">{{ $tasks->count() }}</span>
                    </header>
                    @if($tasks->isEmpty())
                        <p class="px-5 py-5 text-sm text-muted">{{ $key === 'overdue' ? 'Gecikmiş tapşırıq yoxdur. Əla!' : 'Tapşırıq yoxdur.' }}</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach($tasks as $task)
                                <li class="flex items-center gap-3 px-5 py-3 hover:bg-surface-2 transition-colors">
                                    <form method="POST" action="{{ route('tasks.move', $task) }}" x-data @submit.prevent="glaustApi('{{ route('tasks.move', $task) }}', { method: 'POST', body: { status: 'done' } }).then(() => { $el.closest('li').classList.add('opacity-40'); toast('success', 'Tamamlandı'); setTimeout(() => location.reload(), 500) }).catch(e => toast('error', e.message))">
                                        @csrf
                                        <button class="grid place-items-center size-5 rounded-full border-2 border-line-strong text-transparent hover:border-brand hover:text-brand transition-colors" aria-label="Tamamla: {{ $task->title }}">
                                            <x-icon name="check" class="size-3" :stroke="3"/>
                                        </button>
                                    </form>
                                    <a href="{{ route('tasks.show', $task) }}" class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium truncate">{{ $task->title }}</span>
                                        <span class="block text-xs text-muted truncate">{{ $task->project?->name ?? 'Layihəsiz' }} · <x-status group="task" :value="$task->status" :dot="false" class="!h-auto !p-0 !bg-transparent"/></span>
                                    </a>
                                    <x-status group="priority" :value="$task->priority" class="hidden sm:inline-flex"/>
                                    <span @class(['w-24 text-right text-xs font-mono shrink-0', 'text-danger' => $task->isOverdue(), 'text-muted' => ! $task->isOverdue()])>{{ azdate($task->due_date) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>

        <aside class="space-y-5 lg:sticky lg:top-24">
            <section class="card p-5">
                <h2 class="text-sm font-semibold flex items-center gap-2"><x-icon name="bell" class="size-4 text-saffron"/> {{ __('Yeni xatırlatma') }}</h2>
                <form method="POST" action="{{ route('reminders.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <x-input name="title" :label="__('Nə xatırladılsın?')" required :placeholder="__('Məs: Bankla görüş')"/>
                    <x-input name="remind_at" type="datetime-local" :label="__('Nə vaxt?')" required :value="now()->addHour()->startOfHour()->format('Y-m-d\TH:i')"/>
                    <x-input name="body" type="textarea" :label="__('Qeyd')" rows="2"/>
                    <button class="btn btn-primary w-full"><x-icon name="plus" class="size-4"/> {{ __('Əlavə et') }}</button>
                    <p class="text-xs text-muted">{{ __('Vaxtı çatanda header-də görünəcək və mailinizə göndəriləcək.') }}</p>
                </form>
            </section>

            <section class="card overflow-hidden">
                <header class="flex items-center justify-between px-5 h-12 border-b border-line">
                    <h2 class="text-sm font-semibold">{{ __('Xatırlatmalar') }}</h2>
                    <span class="text-xs font-mono text-muted">{{ $reminders->count() }}</span>
                </header>
                @forelse($reminders as $r)
                    <div class="px-5 py-3 border-b border-line last:border-0">
                        <div class="flex items-start gap-2">
                            <div class="min-w-0 flex-1">
                                <a href="{{ $r->url ? url($r->url) : '#' }}" class="text-sm font-medium hover:text-brand-ink">{{ $r->title }}</a>
                                @if($r->body)<p class="text-xs text-muted mt-0.5">{{ $r->body }}</p>@endif
                                <div class="mt-1.5 flex items-center gap-2 text-[11px]">
                                    <span class="badge badge-slate !h-5">{{ $r->sourceLabel() }}</span>
                                    <span @class(['font-mono', 'text-danger' => $r->isOverdue(), 'text-faint' => ! $r->isOverdue()])>{{ azdate($r->remind_at, true) }}</span>
                                    @if($r->snoozed_until && $r->snoozed_until->isFuture())<span class="text-faint">{{ __('· ertələnib') }}</span>@endif
                                </div>
                            </div>
                            <form method="POST" action="{{ route('reminders.done', $r) }}">@csrf
                                <button class="btn btn-ghost btn-sm btn-icon" title="{{ __('Oxundu') }}"><x-icon name="check" class="size-4"/></button>
                            </form>
                            @if($r->source === 'personal')
                                <x-delete-form :action="route('reminders.destroy', $r)" label="" :message="__('Xatırlatma silinsin?')"/>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-6 text-sm text-muted text-center">{{ __('Aktiv xatırlatma yoxdur.') }}</p>
                @endforelse
            </section>
        </aside>
    </div>
</x-layouts.app>
