<x-layouts.app :title="__('Kateqoriyalar')">
    <x-page-header :title="__('Tənzimləmələr')" icon="settings"/>
    @include('settings._nav')
    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start max-w-4xl">
        <section class="card overflow-hidden">
            <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">{{ __('Bank əməliyyatlarının kateqoriyaları') }}</h2></header>
            <ul class="divide-y divide-line">
                @forelse($categories as $c)
                    <li class="flex items-center gap-3 px-5 py-3">
                        <span class="size-3 rounded-full" style="background: {{ $c->color ?? '#94a3b8' }}"></span>
                        <span class="flex-1 text-sm">{{ $c->name }}</span>
                        <span class="text-xs text-muted font-mono">{{ $c->transactions_count }} əməliyyat</span>
                        @can('settings.update')<x-delete-form :action="route('settings.categories.destroy', $c)" label="" :message="'«'.$c->name.'» silinsin? Əməliyyatlar kateqoriyasız qalacaq.'"/>@endcan
                    </li>
                @empty
                    <li class="px-5 py-6 text-sm text-muted">{{ __('Kateqoriya yoxdur.') }}</li>
                @endforelse
            </ul>
        </section>
        @can('settings.update')
            <form method="POST" action="{{ route('settings.categories.store') }}" class="card p-5 space-y-4">
                @csrf
                <h2 class="text-sm font-semibold">{{ __('Yeni kateqoriya') }}</h2>
                <x-input name="name" :label="__('Ad')" required/>
                <x-field :label="__('Rəng')" name="color"><input type="color" name="color" value="#0f9d8a" class="h-10 w-20 rounded-lg border border-line bg-surface p-1"></x-field>
                <button class="btn btn-primary w-full"><x-icon name="plus" class="size-4"/> {{ __('Əlavə et') }}</button>
            </form>
        @endcan
    </div>
</x-layouts.app>
