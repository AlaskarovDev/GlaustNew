<x-layouts.app :title="__('İcazələr')" wide>
    <x-page-header :title="__('İcazələrin idarə olunması')" icon="shield" :subtitle="__('Kim nəyi görə, yarada, redaktə edə, silə bilər — rollar üzrə və hər istifadəçi üçün ayrıca')"/>
    @include('settings._nav')
    @php $canEdit = auth()->user()->can('users.update'); @endphp

    <nav class="flex gap-1 mb-4">
        <a href="{{ route('settings.permissions.index') }}" @class(['btn btn-sm', 'bg-brand-soft text-brand-ink' => $tab === 'roles', 'btn-ghost' => $tab !== 'roles'])>{{ __('Rollar üzrə') }}</a>
        <a href="{{ route('settings.permissions.index', ['tab' => 'users']) }}" @class(['btn btn-sm', 'bg-brand-soft text-brand-ink' => $tab === 'users', 'btn-ghost' => $tab !== 'users'])>{{ __('İstifadəçi üzrə') }} ({{ $users->count() }})</a>
    </nav>

    @if($tab === 'roles')
        <form method="POST" action="{{ route('settings.permissions.roles') }}" class="card overflow-hidden">
            @csrf @method('PUT')
            <div class="overflow-auto max-h-[75vh]">
                <table class="text-[13px] border-separate border-spacing-0 min-w-full">
                    <thead class="sticky top-0 z-20 bg-surface">
                        <tr>
                            <th class="sticky left-0 z-30 bg-surface-2 px-4 py-3 text-left font-semibold min-w-[220px] border-b border-line">{{ __('Modul · əməliyyat') }}</th>
                            @foreach($roles as $role)
                                <th class="px-3 py-3 text-center font-semibold whitespace-nowrap bg-surface-2 border-b border-l border-line min-w-[110px]">
                                    {{ __($role->name) }}<div class="text-[11px] font-normal text-muted">{{ $role->users_count }} {{ __('istifadəçi') }}</div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($modules as $module => $m)
                            <tr><td colspan="{{ $roles->count() + 1 }}" class="sticky left-0 px-4 pt-4 pb-1 text-xs font-bold uppercase tracking-wider text-ink-2 bg-surface">{{ $m['label'] }}</td></tr>
                            @foreach($m['actions'] as $action)
                                <tr class="hover:bg-surface-2">
                                    <td class="sticky left-0 z-10 bg-surface px-4 py-1.5 border-b border-line">{{ $actions[$action] ?? $action }} <span class="text-[11px] font-mono text-faint">{{ $module }}.{{ $action }}</span></td>
                                    @foreach($roles as $role)
                                        <td class="text-center border-b border-l border-line py-1.5">
                                            @if($role->is_admin)
                                                <x-icon name="check" class="size-4 text-success inline" title="{{ __('Admin hər şeyə icazəlidir') }}"/>
                                            @else
                                                <input type="checkbox" name="roles[{{ $role->id }}][]" value="{{ $module }}.{{ $action }}" class="checkbox"
                                                    @checked($role->allows("$module.$action")) @disabled(! $canEdit) aria-label="{{ __($role->name) }}: {{ $m['label'] }} — {{ $actions[$action] ?? $action }}">
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <footer class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-line">
                <p class="text-[11px] text-muted">{{ __('Hər hansı əməliyyat verilibsə, həmin modulda «Baxış» avtomatik əlavə olunur. Admin rolu dəyişdirilmir. Tarifdə olmayan modullar icazə verilsə də görünməz.') }}</p>
                @if($canEdit)<button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>@endif
            </footer>
        </form>
    @else
        <section class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-[13px]">
                    <thead><tr><th>{{ __('İstifadəçi') }}</th><th>{{ __('Rol') }}</th><th>{{ __('Görə bildiyi modullar') }}</th><th>{{ __('Öz istisnaları') }}</th><th class="w-32"></th></tr></thead>
                    <tbody>
                    @foreach($users as $u)
                        @php
                            $eff = \App\Support\Permissions::effective($u);
                            $seen = collect($modules)->filter(fn ($m, $k) => ($eff["$k.view"]['final'] ?? false))->map(fn ($m) => $m['label']);
                            $o = $u->permission_overrides ?? [];
                        @endphp
                        <tr>
                            <td data-label="{{ __('İstifadəçi') }}"><span class="font-medium">{{ $u->name }}</span><div class="text-[11px] text-muted">{{ $u->email }}</div></td>
                            <td data-label="{{ __('Rol') }}"><span class="badge {{ $u->role?->is_admin ? 'badge-teal' : 'badge-slate' }}">{{ \App\Support\Permissions::roleLabel($u->role) }}</span></td>
                            <td data-label="{{ __('Görə bildiyi modullar') }}" class="text-[12px] text-muted max-w-[420px]">{{ $seen->implode(', ') ?: '—' }}</td>
                            <td data-label="{{ __('Öz istisnaları') }}">
                                @if(count($o['grant'] ?? []))<span class="badge badge-green">+{{ count($o['grant']) }} {{ __('icazə') }}</span>@endif
                                @if(count($o['deny'] ?? []))<span class="badge badge-rose">−{{ count($o['deny']) }} {{ __('qadağa') }}</span>@endif
                                @if(! count($o['grant'] ?? []) && ! count($o['deny'] ?? []))<span class="text-faint">—</span>@endif
                            </td>
                            <td class="text-right"><a href="{{ route('settings.permissions.user', $u) }}" class="btn btn-secondary btn-sm">{{ __('İcazələr') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layouts.app>
