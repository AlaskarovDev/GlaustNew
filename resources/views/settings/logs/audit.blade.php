@use('App\Http\Controllers\Settings\LogController')
<x-layouts.app title="Audit jurnalı">
    @php
        $colors = ['created' => 'green', 'updated' => 'blue', 'deleted' => 'rose', 'restored' => 'teal'];
        $show = fn ($v) => is_bool($v) ? ($v ? 'bəli' : 'xeyr') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (($v === null || $v === '') ? '—' : \Illuminate\Support\Str::limit((string) $v, 80)));
    @endphp
    <x-page-header title="Tənzimləmələr" icon="settings"/>
    @include('settings._nav')
    <div class="card overflow-hidden">
        <form method="GET" class="flex flex-wrap items-center gap-2 p-4 border-b border-line" x-data>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Qeyd üzrə axtar" class="input !w-56" aria-label="Axtarış">
            <select name="user_id" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="İstifadəçi"><option value="">İstifadəçi: hamısı</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected(request('user_id') == $id)>{{ $n }}</option>@endforeach</select>
            <select name="type" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="Obyekt"><option value="">Obyekt: hamısı</option>@foreach(LogController::types() as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select>
            <select name="action" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="Əməliyyat"><option value="">Əməliyyat: hamısı</option>@foreach(LogController::actions() as $k => $v)<option value="{{ $k }}" @selected(request('action') === $k)>{{ $v }}</option>@endforeach</select>
            <input type="date" name="from" value="{{ request('from') }}" class="input !h-9 !w-[150px] font-mono text-[13px]" aria-label="Tarixdən">
            <input type="date" name="to" value="{{ request('to') }}" class="input !h-9 !w-[150px] font-mono text-[13px]" aria-label="Tarixədək">
            <button class="btn btn-secondary btn-sm h-9"><x-icon name="filter" class="size-4"/></button>
            @can('logs.export')
                <span class="ml-auto flex gap-2">
                    <a href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}" class="btn btn-secondary btn-sm h-9"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
                    <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}" class="btn btn-secondary btn-sm h-9"><x-icon name="file-pdf" class="size-4 text-danger"/> PDF</a>
                </span>
            @endcan
        </form>
        <ol class="divide-y divide-line">
            @forelse($logs as $a)
                <li class="flex gap-4 px-5 py-4" x-data="{ open: false }">
                    <x-avatar :user="$a->user" size="sm"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                            <span class="font-medium">{{ $a->user?->name ?? 'Sistem' }}</span>
                            <span class="badge badge-{{ $colors[$a->action] ?? 'slate' }} !h-5">{{ LogController::actions()[$a->action] ?? $a->action }}</span>
                            <span class="text-muted">{{ LogController::types()[$a->auditable_type] ?? $a->auditable_type }}:</span>
                            <span class="text-ink-2 break-words">{{ $a->label }}</span>
                        </div>
                        <div class="text-xs text-faint mt-0.5 font-mono">{{ azdate($a->created_at, true) }} · {{ $a->ip_address ?? 'sistem' }}</div>
                        @if($a->old_values || $a->new_values)
                            <button type="button" class="mt-1.5 text-xs text-brand-ink hover:underline" @click="open = !open" x-text="open ? 'Gizlət' : 'Təfərrüat'">Təfərrüat</button>
                            <dl x-show="open" x-collapse x-cloak class="mt-2 rounded-lg bg-surface-2 p-3 text-xs space-y-1 font-mono">
                                @foreach(($a->action === 'updated' ? $a->new_values : ($a->new_values ?: $a->old_values)) ?? [] as $field => $value)
                                    <div class="flex flex-wrap gap-x-2"><dt class="text-muted">{{ $field }}:</dt>
                                        <dd>@if($a->action === 'updated')<span class="line-through text-faint">{{ $show($a->old_values[$field] ?? null) }}</span> → @endif<span class="text-ink">{{ $show($value) }}</span></dd></div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </li>
            @empty
                <li><x-empty icon="history" title="Qeyd tapılmadı"/></li>
            @endforelse
        </ol>
        {{ $logs->links() }}
    </div>
</x-layouts.app>
