<x-layouts.app title="Giriş-çıxış logları">
    <x-page-header title="Tənzimləmələr" icon="settings"/>
    @include('settings._nav')

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5 stagger">
        @foreach(['login' => ['Girişlər (7 gün)', 'text-success'], 'failed' => ['Uğursuz cəhdlər', 'text-danger'], 'locked' => ['Bağlanmış hesab', 'text-danger'], 'session_expired' => ['Bitmiş sessiyalar', 'text-saffron']] as $e => [$label, $cls])
            <div class="card p-4" style="--i:{{ $loop->index }}"><div class="text-xs text-muted">{{ $label }}</div><div class="text-2xl font-semibold font-mono {{ ($stats[$e] ?? 0) ? $cls : '' }}">{{ $stats[$e] ?? 0 }}</div></div>
        @endforeach
    </div>

    <div class="card overflow-hidden">
        <form method="GET" class="flex flex-wrap items-center gap-2 p-4 border-b border-line" x-data>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Email və ya IP" class="input !w-56" aria-label="Axtarış">
            <select name="user_id" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="İstifadəçi"><option value="">İstifadəçi: hamısı</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected(request('user_id') == $id)>{{ $n }}</option>@endforeach</select>
            <select name="event" class="input !h-9 !w-auto text-[13px]" @change="$el.form.requestSubmit()" aria-label="Hadisə"><option value="">Hadisə: hamısı</option>@foreach(\App\Http\Controllers\Settings\LogController::EVENTS as $k => $v)<option value="{{ $k }}" @selected(request('event') === $k)>{{ $v }}</option>@endforeach</select>
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
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>Tarix</th><th>İstifadəçi</th><th>Hadisə</th><th>IP</th><th>Cihaz</th><th class="!text-right">Sessiya müddəti</th></tr></thead>
                <tbody>
                @forelse($logs as $l)
                    <tr>
                        <td data-label="Tarix" class="font-mono text-xs whitespace-nowrap">{{ azdate($l->created_at, true) }}:{{ $l->created_at->format('s') }}</td>
                        <td data-label="İstifadəçi"><div class="text-sm text-ink">{{ $l->user?->name ?? '—' }}</div><div class="text-xs text-muted">{{ $l->email }}</div></td>
                        <td data-label="Hadisə">@include('settings.logs._event', ['event' => $l->event])</td>
                        <td data-label="IP" class="font-mono text-xs">{{ $l->ip_address }}</td>
                        <td data-label="Cihaz" class="text-xs" title="{{ $l->user_agent }}">{{ $l->device }}</td>
                        <td data-label="Müddət" class="num text-xs">
                            @if($l->duration_seconds !== null)
                                {{ $l->duration_seconds >= 3600 ? intdiv($l->duration_seconds, 3600).' saat ' : '' }}{{ intdiv($l->duration_seconds % 3600, 60) }} dəq
                            @elseif($l->event === 'login' && ! $l->closed)
                                <span class="badge badge-green !h-5">açıqdır</span>
                            @else — @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty icon="history" title="Log tapılmadı"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </div>
</x-layouts.app>
