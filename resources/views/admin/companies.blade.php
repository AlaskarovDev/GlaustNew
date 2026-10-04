<x-layouts.admin :title="__('Şirkətlər')">
    <x-page-header :title="__('Şirkətlər')">
        <x-slot:actions><a href="{{ route('admin.companies.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Yeni şirkət') }}</a></x-slot:actions>
    </x-page-header>
    <div class="card overflow-hidden">
        <form method="GET" class="flex flex-wrap gap-2 p-4 border-b border-line" x-data>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Ad, VÖEN, email') }}" class="input !w-72" aria-label="{{ __('Axtarış') }}">
            <select name="status" class="input !h-10 !w-auto" @change="$el.form.requestSubmit()" aria-label="{{ __('Status') }}"><option value="">{{ __('Status: hamısı') }}</option>@foreach(status_options('subscription') as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        </form>
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>{{ __('Şirkət') }}</th><th>{{ __('VÖEN') }}</th><th>{{ __('Tarif') }}</th><th>{{ __('İstifadəçi') }}</th><th>{{ __('Status') }}</th><th>{{ __('Bitmə') }}</th></tr></thead>
                <tbody>@foreach($companies as $c)
                    <tr>
                        <td data-label="Şirkət"><a href="{{ route('admin.companies.show', $c) }}" class="font-medium hover:text-brand-ink">{{ $c->name }}</a><div class="text-xs text-muted">{{ $c->email }}</div></td>
                        <td data-label="VÖEN" class="font-mono text-xs">{{ $c->voen ?? '—' }}</td>
                        <td data-label="Tarif">{{ $c->plan?->name ?? '—' }}</td>
                        <td data-label="İstifadəçi" class="font-mono">{{ $c->users_count }}{{ $c->plan ? ' / '.$c->plan->max_users : '' }}</td>
                        <td data-label="Status"><x-status group="subscription" :value="$c->subscription_status"/></td>
                        <td data-label="Bitmə" class="font-mono text-xs">{{ azdate($c->subscription_status === 'trial' ? $c->trial_ends_at : $c->subscription_ends_at) }}</td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        {{ $companies->links() }}
    </div>
</x-layouts.admin>
