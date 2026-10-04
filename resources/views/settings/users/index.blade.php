<x-layouts.app :title="__('İstifadəçilər')">
    <x-page-header :title="__('Tənzimləmələr')" icon="settings">
        <x-slot:actions>
            @can('users.create')
                <a href="{{ route('settings.users.create') }}" class="btn btn-primary"><x-icon name="user-plus" class="size-4"/> {{ __('İstifadəçi dəvət et') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>
    @include('settings._nav')

    <div class="card overflow-hidden">
        <form method="GET" class="flex flex-wrap items-center gap-2 p-4 border-b border-line">
            <div class="relative flex-1 min-w-[200px] max-w-sm">
                <x-icon name="search" class="size-4 text-faint absolute left-3 top-1/2 -translate-y-1/2"/>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Ad və ya email') }}" class="input pl-9" aria-label="{{ __('Axtarış') }}">
            </div>
            <select name="role_id" class="input !h-9 !w-auto text-[13px]" aria-label="{{ __('Rol') }}" x-data @change="$el.form.requestSubmit()">
                <option value="">{{ __('Rol: hamısı') }}</option>@foreach($roles as $id => $n)<option value="{{ $id }}" @selected(request('role_id') == $id)>{{ $n }}</option>@endforeach
            </select>
            <select name="status" class="input !h-9 !w-auto text-[13px]" aria-label="{{ __('Status') }}" x-data @change="$el.form.requestSubmit()">
                <option value="">{{ __('Status: hamısı') }}</option><option value="active" @selected(request('status') === 'active')>{{ __('Aktiv') }}</option><option value="inactive" @selected(request('status') === 'inactive')>{{ __('Deaktiv') }}</option>
            </select>
            <span class="ml-auto text-xs text-muted">{{ __('Aktiv:') }} <span class="font-mono text-ink">{{ $count }}{{ $limit ? ' / '.$limit : '' }}</span></span>
        </form>
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>{{ __('İstifadəçi') }}</th><th>{{ __('Rol') }}</th><th>{{ __('Son giriş') }}</th><th>{{ __('Status') }}</th><th class="w-px"></th></tr></thead>
                <tbody>
                @foreach($users as $u)
                    <tr @class(['opacity-60' => ! $u->is_active])>
                        <td data-label="İstifadəçi">
                            <div class="flex items-center gap-3">
                                <span class="relative"><x-avatar :user="$u"/>@if(in_array($u->id, $online))<span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full bg-success ring-2 ring-surface" title="{{ __('Onlayn') }}"></span>@endif</span>
                                <div class="min-w-0 text-left">
                                    <div class="font-medium text-ink truncate">{{ $u->name }} @if($u->id === auth()->id())<span class="text-xs text-muted">{{ __('(siz)') }}</span>@endif</div>
                                    <div class="text-xs text-muted truncate">{{ $u->email }}{{ $u->position ? ' · '.$u->position : '' }}</div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Rol"><span @class(['badge', 'badge-teal' => $u->role?->is_admin, 'badge-slate' => ! $u->role?->is_admin])>{{ $u->role?->name ?? '—' }}</span>
                            @if($u->hasTwoFactor())<span class="badge badge-green !h-5 ml-1" title="{{ __('2FA aktivdir') }}"><x-icon name="shield" class="size-3"/></span>@endif</td>
                        <td data-label="Son giriş" class="text-xs"><span class="font-mono">{{ azdate($u->last_login_at, true) }}</span>@if($u->last_login_ip)<div class="text-faint font-mono">{{ $u->last_login_ip }}</div>@endif</td>
                        <td data-label="Status">
                            @if($u->invitation_token)<span class="badge badge-amber">{{ __('Dəvət gözləyir') }}</span>
                            @elseif($u->isLocked())<span class="badge badge-rose">{{ __('Kilidlənib') }}</span>
                            @elseif($u->is_active)<span class="badge badge-green badge-dot">{{ __('Aktiv') }}</span>
                            @else<span class="badge badge-slate">{{ __('Deaktiv') }}</span>@endif
                        </td>
                        <td data-label="" class="whitespace-nowrap text-right">
                            <div x-data="{ open: false }" class="relative inline-block" @click.outside="open = false">
                                <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="open = !open" aria-label="Əməliyyatlar: {{ $u->name }}"><x-icon name="more" class="size-4"/></button>
                                <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 z-20 mt-1 w-56 card !shadow-[var(--shadow-pop)] p-1 text-left">
                                    @can('users.update')
                                        <a href="{{ route('settings.users.edit', $u->id) }}" class="flex items-center gap-2 px-3 h-9 rounded-lg text-sm hover:bg-surface-2"><x-icon name="pencil" class="size-4"/> {{ __('Redaktə') }}</a>
                                        @if($u->invitation_token)
                                            <form method="POST" action="{{ route('settings.users.invite', $u->id) }}">@csrf<button class="w-full flex items-center gap-2 px-3 h-9 rounded-lg text-sm hover:bg-surface-2"><x-icon name="send" class="size-4"/> {{ __('Dəvəti yenidən göndər') }}</button></form>
                                        @endif
                                        @if($u->isLocked())
                                            <form method="POST" action="{{ route('settings.users.unlock', $u->id) }}">@csrf<button class="w-full flex items-center gap-2 px-3 h-9 rounded-lg text-sm hover:bg-surface-2"><x-icon name="key" class="size-4"/> {{ __('Kilidi aç') }}</button></form>
                                        @endif
                                        @if($u->id !== auth()->id())
                                            <form method="POST" action="{{ route('settings.users.logout', $u->id) }}" data-confirm="{{ $u->name }} bütün cihazlardan çıxarılacaq." data-confirm-title="Məcburi çıxış" data-confirm-action="{{ __('Çıxar') }}">@csrf
                                                <button class="w-full flex items-center gap-2 px-3 h-9 rounded-lg text-sm hover:bg-surface-2"><x-icon name="log-out" class="size-4"/> {{ __('Bütün sessiyaları bağla') }}</button></form>
                                        @endif
                                    @endcan
                                    @can('users.delete')
                                        @if($u->id !== auth()->id() && $u->is_active)
                                            <form method="POST" action="{{ route('settings.users.destroy', $u->id) }}" data-confirm="{{ $u->name }} deaktiv ediləcək və sistemdən çıxarılacaq. Tarixçəsi qorunur." data-confirm-title="Deaktiv edilsin?" data-confirm-action="{{ __('Deaktiv et') }}">@csrf @method('DELETE')
                                                <button class="w-full flex items-center gap-2 px-3 h-9 rounded-lg text-sm text-danger hover:bg-danger-soft"><x-icon name="lock" class="size-4"/> {{ __('Deaktiv et') }}</button></form>
                                        @endif
                                    @endcan
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    </div>
</x-layouts.app>
