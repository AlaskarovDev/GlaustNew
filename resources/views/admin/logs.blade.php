<x-layouts.admin title="Sistem logları">
    <x-page-header title="Sistem logları"/>
    <div class="grid xl:grid-cols-[minmax(0,1fr)_420px] gap-6 items-start">
        <section class="card overflow-hidden">
            <header class="px-5 h-14 flex items-center justify-between border-b border-line">
                <h2 class="text-sm font-semibold">Giriş hadisələri (bütün şirkətlər)</h2>
                <form method="GET" x-data><select name="event" class="input !h-8 text-xs !w-auto" @change="$el.form.requestSubmit()" aria-label="Hadisə"><option value="">hamısı</option>@foreach(\App\Http\Controllers\Settings\LogController::EVENTS as $k => $v)<option value="{{ $k }}" @selected(request('event') === $k)>{{ $v }}</option>@endforeach</select></form>
            </header>
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr><th>Tarix</th><th>Şirkət</th><th>Email</th><th>Hadisə</th><th>IP</th></tr></thead>
                    <tbody>@foreach($logins as $l)
                        <tr><td data-label="Tarix" class="font-mono text-xs">{{ azdate($l->created_at, true) }}</td><td data-label="Şirkət" class="text-xs">{{ $companies[$l->company_id] ?? 'Platforma' }}</td>
                            <td data-label="Email" class="text-sm">{{ $l->email }}</td><td data-label="Hadisə">@include('settings.logs._event', ['event' => $l->event])</td><td data-label="IP" class="font-mono text-xs">{{ $l->ip_address }}</td></tr>
                    @endforeach</tbody>
                </table>
            </div>
            {{ $logins->links() }}
        </section>
        <section class="card overflow-hidden">
            <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">Göndərilməyən maillər</h2></header>
            <ul class="divide-y divide-line">
                @forelse($mails as $m)
                    <li class="px-5 py-3 text-sm"><div class="flex justify-between gap-2"><span class="truncate">{{ $m->to }}</span><span class="font-mono text-xs text-muted shrink-0">{{ azdate($m->created_at, true) }}</span></div>
                        <div class="text-xs text-danger break-words mt-0.5">{{ \Illuminate\Support\Str::limit($m->error, 180) }}</div></li>
                @empty<li class="px-5 py-6 text-sm text-muted">Xəta yoxdur.</li>@endforelse
            </ul>
        </section>
    </div>
</x-layouts.admin>
