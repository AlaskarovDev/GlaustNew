<x-layouts.app title="Tənzimləmələr">
    <x-page-header title="Tənzimləmələr" icon="settings" subtitle="Şirkət, istifadəçilər, rollar, mail və loglar"/>
    @include('settings._nav')

    <div class="card p-5 mb-6 flex flex-wrap items-center gap-x-8 gap-y-3">
        <div><div class="text-xs text-muted">Şirkət</div><div class="font-semibold">{{ $company->name }}</div></div>
        <div><div class="text-xs text-muted">Tarif</div><div class="font-semibold">{{ $company->plan?->name ?? '—' }}</div></div>
        <div><div class="text-xs text-muted">Abunə</div><x-status group="subscription" :value="$company->subscription_status"/></div>
        <div><div class="text-xs text-muted">{{ $company->subscription_status === 'trial' ? 'Sınaq bitir' : 'Abunə bitir' }}</div><div class="font-mono">{{ azdate($company->subscription_status === 'trial' ? $company->trial_ends_at : $company->subscription_ends_at) }}</div></div>
        <div><div class="text-xs text-muted">İstifadəçilər</div><div class="font-mono">{{ $usersCount }}{{ $company->plan ? ' / '.$company->plan->max_users : '' }}</div></div>
    </div>

    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4 stagger">
        @foreach($sections as [$route, $icon, $title, $text])
            <a href="{{ route('settings.'.$route) }}" class="card card-hover p-5 flex gap-4 group" style="--i:{{ $loop->index }}">
                <span class="grid place-items-center size-11 shrink-0 rounded-xl bg-brand-soft text-brand"><x-icon :name="$icon" class="size-5"/></span>
                <span class="min-w-0">
                    <span class="block font-semibold group-hover:text-brand-ink">{{ $title }}</span>
                    <span class="block text-sm text-muted mt-0.5">{{ $text }}</span>
                </span>
            </a>
        @endforeach
    </div>
</x-layouts.app>
