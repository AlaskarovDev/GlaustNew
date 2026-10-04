@php
    $u = auth()->user();
    $links = array_filter([
        $u->can('settings.view') ? ['settings.company', __('Şirkət')] : null,
        $u->can('users.view') ? ['settings.users.index', __('İstifadəçilər'), 'settings.users.*'] : null,
        $u->can('users.view') ? ['settings.roles.index', __('Rollar'), 'settings.roles.*'] : null,
        $u->can('settings.view') ? ['settings.general', __('Ümumi')] : null,
        $u->can('settings.view') ? ['settings.approvals', __('Təsdiq axını')] : null,
        $u->can('settings.view') ? ['settings.mail', 'Mail'] : null,
        $u->can('settings.view') ? ['settings.categories', __('Kateqoriyalar')] : null,
        $u->can('logs.view') ? ['settings.logs.logins', __('Girişlər')] : null,
        $u->can('logs.view') ? ['settings.logs.sessions', __('Sessiyalar')] : null,
        $u->can('logs.view') ? ['settings.logs.audit', 'Audit'] : null,
        $u->can('logs.view') ? ['settings.logs.mail', __('Mail jurnalı')] : null,
    ]);
@endphp
<nav class="flex gap-6 border-b border-line mb-6 overflow-x-auto" aria-label="{{ __('Tənzimləmələr') }}">
    <a href="{{ route('settings.index') }}" @class(['tab-link', 'is-active' => request()->routeIs('settings.index')])>{{ __('Ümumi baxış') }}</a>
    @foreach($links as $l)
        <a href="{{ route($l[0]) }}" @class(['tab-link', 'is-active' => request()->routeIs($l[2] ?? $l[0])])>{{ $l[1] }}</a>
    @endforeach
</nav>
