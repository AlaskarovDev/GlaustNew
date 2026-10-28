<x-layouts.app :title="__('İcazələr').': '.$user->name" wide>
    <x-page-header :title="__('İcazələr').': '.$user->name" icon="shield" :back="route('settings.permissions.index', ['tab' => 'users'])"
                   :subtitle="__('Rol').': '.\App\Support\Permissions::roleLabel($user->role).' · '.$user->email"/>
    @include('settings._nav')
    @php
        $locked = $user->role?->is_admin || $user->id === auth()->id() || ! auth()->user()->can('users.update');
        $why = $user->role?->is_admin ? __('Admin rolunun icazələri məhdudlaşdırılmır.') : ($user->id === auth()->id() ? __('Öz icazələrinizi dəyişə bilməzsiniz.') : null);
    @endphp

    <form method="GET" class="flex flex-wrap items-end gap-3 mb-4" x-data @change="window.location = @js(route('settings.permissions.user', '__U__')).replace('__U__', $event.target.value)">
        <label><span class="field-label">{{ __('İstifadəçi') }}</span>
            <select class="input min-w-[260px]">@foreach($users as $u)<option value="{{ $u->id }}" @selected($u->id === $user->id)>{{ $u->name }}</option>@endforeach</select></label>
        <div class="flex flex-wrap gap-2 text-[11px] pb-2">
            <span class="badge badge-green">✓ {{ __('icazə var') }}</span>
            <span class="badge badge-slate">— {{ __('icazə yoxdur') }}</span>
            <span class="badge badge-blue">{{ __('Roldan') }}</span><span class="badge badge-amber">{{ __('Öz istisnası') }}</span>
        </div>
    </form>
    @if($why)<div class="card border-saffron/40 bg-saffron-soft/50 p-3 mb-4 text-sm">{{ $why }}</div>@endif

    <form method="POST" action="{{ route('settings.permissions.user.update', $user) }}" class="card overflow-hidden">
        @csrf @method('PUT')
        <div class="overflow-x-auto">
            <table class="table-g text-[13px]">
                <thead><tr><th>{{ __('Modul') }}</th>@foreach($actions as $a => $label)<th class="!text-center">{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($modules as $module => $m)
                    <tr>
                        <td class="font-medium whitespace-nowrap">{{ $m['label'] }}</td>
                        @foreach(array_keys($actions) as $action)
                            @php $e = $effective["$module.$action"] ?? null; @endphp
                            <td class="text-center">
                                @if(! $e)
                                    <span class="text-faint">·</span>
                                @else
                                    <div class="inline-flex flex-col items-center gap-1">
                                        <span @class(['text-base font-semibold', 'text-success' => $e['final'], 'text-faint' => ! $e['final']])
                                              title="{{ __('Roldan') }}: {{ $e['role'] ? '✓' : '—' }}">{{ $e['final'] ? '✓' : '—' }}</span>
                                        <select name="p[{{ $module }}.{{ $action }}]" @disabled($locked) aria-label="{{ $m['label'] }}: {{ $actions[$action] }}"
                                                @class(['input !h-7 !py-0 !px-1.5 !text-[11px] !w-auto', '!border-saffron' => $e['override']])>
                                            <option value="role" @selected(! $e['override'])>{{ __('Roldan') }} ({{ $e['role'] ? '✓' : '—' }})</option>
                                            <option value="grant" @selected($e['override'] === 'grant')>{{ __('İcazə ver') }}</option>
                                            <option value="deny" @selected($e['override'] === 'deny')>{{ __('Qadağan et') }}</option>
                                        </select>
                                    </div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <footer class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-t border-line">
            <p class="text-[11px] text-muted">{{ __('«Roldan» — rolun icazəsi qüvvədədir. «İcazə ver» — rolda olmasa da icazə verilir (Baxış da əlavə olunur). «Qadağan et» — rolda olsa da bağlanır; Baxışı qadağan etmək bütün modulu bağlayır.') }}</p>
            @unless($locked)<button class="btn btn-primary"><x-icon name="check" class="size-4"/> {{ __('Yadda saxla') }}</button>@endunless
        </footer>
    </form>
</x-layouts.app>
