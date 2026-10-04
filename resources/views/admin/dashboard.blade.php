<x-layouts.admin :title="__('Platforma')">
    <x-page-header :title="__('Platforma')" :subtitle="__('TradeFlow üzrə bütün şirkətlər və abunələr')"/>
    <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-6 stagger">
        @foreach([['Şirkətlər', $stats['companies'], null], ['Aktiv abunə', $stats['active'], 'text-success'], ['Sınaqda', $stats['trial'], 'text-saffron'], ['Dayandırılıb', $stats['suspended'], 'text-danger'], ['İstifadəçilər', $stats['users'], null], ['Aylıq gəlir', money($stats['mrr']), null]] as $i => [$l, $v, $c])
            <div class="card p-5" style="--i:{{ $i }}"><div class="text-xs text-muted">{{ $l }}</div><div class="mt-1 text-2xl font-semibold font-mono {{ $c }}">{{ $v }}</div></div>
        @endforeach
    </div>
    <div class="grid lg:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
        <section class="card overflow-hidden">
            <header class="px-5 h-14 flex items-center justify-between border-b border-line"><h2 class="text-sm font-semibold">{{ __('Son qeydiyyatlar') }}</h2><a href="{{ route('admin.companies.index') }}" class="text-xs text-brand-ink">{{ __('Hamısı') }}</a></header>
            <table class="table-g table-stack">
                <thead><tr><th>{{ __('Şirkət') }}</th><th>{{ __('Tarif') }}</th><th>{{ __('İstifadəçi') }}</th><th>{{ __('Status') }}</th><th>{{ __('Tarix') }}</th></tr></thead>
                <tbody>@foreach($recent as $c)
                    <tr><td data-label="Şirkət"><a href="{{ route('admin.companies.show', $c) }}" class="font-medium hover:text-brand-ink">{{ $c->name }}</a></td>
                        <td data-label="Tarif">{{ $c->plan?->name }}</td><td data-label="İstifadəçi" class="font-mono">{{ $c->users_count }}</td>
                        <td data-label="Status"><x-status group="subscription" :value="$c->subscription_status"/></td><td data-label="Tarix" class="font-mono text-xs">{{ azdate($c->created_at) }}</td></tr>
                @endforeach</tbody>
            </table>
        </section>
        <aside class="space-y-6">
            <section class="card p-5">
                <h2 class="text-sm font-semibold mb-3">{{ __('7 gündə bitən sınaqlar') }}</h2>
                @forelse($expiring as $c)
                    <a href="{{ route('admin.companies.show', $c) }}" class="flex justify-between py-2 border-t border-line first:border-0 text-sm hover:text-brand-ink"><span>{{ $c->name }}</span><span class="font-mono text-xs">{{ azdate($c->trial_ends_at) }}</span></a>
                @empty<p class="text-sm text-muted">{{ __('Yoxdur.') }}</p>@endforelse
            </section>
            <section class="card p-5 text-sm space-y-2">
                <h2 class="font-semibold mb-1">{{ __('Son 24 saat') }}</h2>
                <div class="flex justify-between"><span class="text-muted">{{ __('Uğursuz giriş cəhdləri') }}</span><span class="font-mono {{ $failedLogins ? 'text-danger' : '' }}">{{ $failedLogins }}</span></div>
                <div class="flex justify-between"><span class="text-muted">{{ __('Göndərilməyən maillər') }}</span><span class="font-mono {{ $failedMails ? 'text-danger' : '' }}">{{ $failedMails }}</span></div>
                <a href="{{ route('admin.logs') }}" class="inline-block pt-2 text-xs text-brand-ink">{{ __('Sistem logları →') }}</a>
            </section>
        </aside>
    </div>
</x-layouts.admin>
