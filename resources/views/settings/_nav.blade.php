@php
    $u = auth()->user();
    $links = array_filter([
        $u->can('settings.view') ? ['settings.company', 'Şirkət'] : null,
        $u->can('users.view') ? ['settings.users.index', 'İstifadəçilər', 'settings.users.*'] : null,
        $u->can('users.view') ? ['settings.roles.index', 'Rollar', 'settings.roles.*'] : null,
        $u->can('settings.view') ? ['settings.general', 'Ümumi'] : null,
        $u->can('settings.view') ? ['settings.approvals', 'Təsdiq axını'] : null,
        $u->can('settings.view') ? ['settings.mail', 'Mail'] : null,
        $u->can('settings.view') ? ['settings.categories', 'Kateqoriyalar'] : null,
        $u->can('logs.view') ? ['settings.logs.logins', 'Girişlər'] : null,
        $u->can('logs.view') ? ['settings.logs.sessions', 'Sessiyalar'] : null,
        $u->can('logs.view') ? ['settings.logs.audit', 'Audit'] : null,
        $u->can('logs.view') ? ['settings.logs.mail', 'Mail jurnalı'] : null,
    ]);
@endphp
<nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="Tənzimləmələr">
    <a href="{{ route('settings.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('settings.index')])>Ümumi baxış</a>
    @foreach($links as $l)
        <a href="{{ route($l[0]) }}" @class(['tab-link', 'is-active' => request()->routeIs($l[2] ?? $l[0])])>{{ $l[1] }}</a>
    @endforeach
</nav>
