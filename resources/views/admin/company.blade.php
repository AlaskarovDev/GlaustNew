<x-layouts.admin :title="$company->name">
    <x-page-header :title="$company->name" :subtitle="($company->voen ? 'VÖEN '.$company->voen.' · ' : '').'qeydiyyat '.azdate($company->created_at)" :back="route('admin.companies.index')"/>
    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card overflow-hidden">
                <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">İstifadəçilər ({{ $users->count() }})</h2></header>
                <table class="table-g table-stack">
                    <thead><tr><th>Ad</th><th>Rol</th><th>Son giriş</th><th>Status</th></tr></thead>
                    <tbody>@foreach($users as $u)
                        <tr><td data-label="Ad"><div class="font-medium">{{ $u->name }}</div><div class="text-xs text-muted">{{ $u->email }}</div></td>
                            <td data-label="Rol">{{ $u->role?->name }}</td><td data-label="Son giriş" class="font-mono text-xs">{{ azdate($u->last_login_at, true) }}</td>
                            <td data-label="Status">{!! $u->is_active ? '<span class="badge badge-green">Aktiv</span>' : '<span class="badge badge-slate">Deaktiv</span>' !!}</td></tr>
                    @endforeach</tbody>
                </table>
            </section>
            <section class="card overflow-hidden">
                <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">Son giriş hadisələri</h2></header>
                <table class="table-g table-stack">
                    <tbody>@foreach($logins as $l)
                        <tr><td data-label="Tarix" class="font-mono text-xs">{{ azdate($l->created_at, true) }}</td><td data-label="Email">{{ $l->email }}</td>
                            <td data-label="Hadisə">@include('settings.logs._event', ['event' => $l->event])</td><td data-label="IP" class="font-mono text-xs">{{ $l->ip_address }}</td></tr>
                    @endforeach</tbody>
                </table>
            </section>
        </div>
        <aside class="space-y-6">
            <form method="POST" action="{{ route('admin.companies.update', $company) }}" class="card p-5 space-y-4">
                @csrf @method('PUT')
                <h2 class="text-sm font-semibold">Abunə</h2>
                <x-select name="plan_id" label="Tarif" :options="$plans->pluck('name', 'id')->all()" :value="$company->plan_id" placeholder="—"/>
                <x-select name="subscription_status" label="Status" :options="status_options('subscription')" :value="$company->subscription_status" required/>
                <x-input name="trial_ends_at" type="date" label="Sınaq bitir" :value="$company->trial_ends_at"/>
                <x-input name="subscription_ends_at" type="date" label="Abunə bitir" :value="$company->subscription_ends_at" hint="Boş — müddətsiz"/>
                <button class="btn btn-primary w-full">Yadda saxla</button>
            </form>
            <section class="card p-5 text-sm space-y-2">
                <div class="flex justify-between"><span class="text-muted">Fayllar</span><span class="font-mono">{{ round($storage / 1048576, 1) }} MB{{ $company->plan ? ' / '.$company->plan->max_storage_mb.' MB' : '' }}</span></div>
                <div class="flex justify-between"><span class="text-muted">Öz SMTP</span><span>{{ $company->hasOwnSmtp() ? 'bəli' : 'xeyr' }}</span></div>
                <div class="flex justify-between"><span class="text-muted">Email</span><span>{{ $company->email }}</span></div>
            </section>
        </aside>
    </div>
</x-layouts.admin>
