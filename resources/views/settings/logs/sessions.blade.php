<x-layouts.app :title="__('Aktiv sessiyalar')">
    <x-page-header :title="__('Tənzimləmələr')" icon="settings"/>
    @include('settings._nav')
    <div class="card overflow-hidden">
        <header class="px-5 py-4 border-b border-line">
            <h2 class="text-base font-semibold">{{ __('Aktiv sessiyalar') }} <span class="text-muted font-mono font-normal text-sm">{{ $sessions->count() }}</span></h2>
            <p class="text-xs text-muted">{{ __('Son') }} {{ config('session.lifetime') }} {{ __('dəqiqədə fəal olan sessiyalar. Bağlanan sessiya dərhal sistemdən çıxarılır.') }}</p>
        </header>
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>{{ __('İstifadəçi') }}</th><th>{{ __('Cihaz') }}</th><th>IP</th><th>{{ __('Son fəaliyyət') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($sessions as $s)
                    <tr>
                        <td data-label="{{ __('İstifadəçi') }}"><div class="font-medium text-ink">{{ $s->name }}</div><div class="text-xs text-muted">{{ $s->email }}</div></td>
                        <td data-label="{{ __('Cihaz') }}">{{ $s->device }} @if($s->current)<span class="badge badge-teal ml-1">{{ __('Siz') }}</span>@endif</td>
                        <td data-label="IP" class="font-mono text-xs">{{ $s->ip }}</td>
                        <td data-label="{{ __('Son fəaliyyət') }}" class="text-xs"><span @class(['inline-flex items-center gap-1.5', 'text-success' => $s->last->gt(now()->subMinutes(5))])>@if($s->last->gt(now()->subMinutes(5)))<span class="size-1.5 rounded-full bg-success"></span>@endif{{ $s->last->diffForHumans() }}</span></td>
                        <td data-label="" class="text-right">
                            @if(! $s->current)
                                @can('users.update')
                                    <form method="POST" action="{{ route('settings.logs.sessions.destroy', $s->id) }}" data-confirm="{{ __(':v1 bu cihazdan çıxarılacaq.', ['v1' => $s->name]) }}" data-confirm-title="Sessiya bağlansın?" data-confirm-action="{{ __('Bağla') }}">@csrf @method('DELETE')
                                        <button class="btn btn-secondary btn-sm"><x-icon name="log-out" class="size-4"/> {{ __('Bağla') }}</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="monitor" :title="__('Aktiv sessiya yoxdur')"/></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
