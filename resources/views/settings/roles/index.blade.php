<x-layouts.app :title="__('Rollar')">
    <x-page-header :title="__('Tənzimləmələr')" icon="settings">
        <x-slot:actions>
            @can('users.create')<a href="{{ route('settings.roles.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Yeni rol') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>
    @include('settings._nav')
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4 stagger">
        @foreach($roles as $r)
            @php $perms = $r->is_admin ? null : collect($r->permissions ?? [])->map(fn ($p) => explode('.', $p)[0])->unique(); @endphp
            <article class="card p-5 flex flex-col" style="--i:{{ $loop->index }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">{{ $r->name }}</h2>
                        <div class="text-xs text-muted mt-0.5">{{ $r->users_count }} {{ __('istifadəçi') }} {{ $r->is_system ? '· sistem rolu' : '' }}</div>
                    </div>
                    @if($r->is_admin)<span class="badge badge-teal"><x-icon name="crown" class="size-3"/> {{ __('Tam icazə') }}</span>@endif
                </div>
                <div class="mt-4 flex flex-wrap gap-1.5 flex-1">
                    @if($r->is_admin)
                        <span class="text-sm text-muted">{{ __('Bütün modullar və əməliyyatlar.') }}</span>
                    @else
                        @foreach($perms as $m)<span class="badge badge-slate">{{ config("glaust.modules.$m.label", $m) }}</span>@endforeach
                    @endif
                </div>
                @unless($r->is_admin)
                    <div class="mt-4 pt-4 border-t border-line flex gap-2">
                        @can('users.update')<a href="{{ route('settings.roles.edit', $r) }}" class="btn btn-secondary btn-sm"><x-icon name="pencil" class="size-4"/> {{ __('İcazələr') }}</a>@endcan
                        @can('users.delete')@unless($r->is_system)<x-delete-form :action="route('settings.roles.destroy', $r)" class="ml-auto" :message="'«'.$r->name.'» rolu silinsin?'"/>@endunless @endcan
                    </div>
                @endunless
            </article>
        @endforeach
    </div>
</x-layouts.app>
