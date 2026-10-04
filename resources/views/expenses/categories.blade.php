<x-layouts.app :title="__('Xərc kateqoriyaları')">
    <x-page-header :title="__('Xərclərin idarəetmə mərkəzi')" icon="receipt" :subtitle="__('Xərcləri qruplaşdırmaq üçün kateqoriyalar')"/>
    @include('expenses._tabs')

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start max-w-5xl">
        <section class="card overflow-hidden">
            @if($categories->isEmpty())
                <x-empty icon="list" :title="__('Kateqoriya yoxdur')" :text="__('Məs: İcarə, Maaş, Nəqliyyat, Kommunal, Vergi və rüsumlar.')"/>
            @else
                <ul class="divide-y divide-line">
                    @foreach($categories as $c)
                        <li class="px-5 py-3" x-data="{ edit: false }">
                            <div class="flex items-center gap-3" x-show="!edit">
                                <span class="size-3 rounded-full shrink-0" style="background: {{ $c->color ?: '#94a3b8' }}"></span>
                                <a href="{{ route('expenses.index', ['category_id' => $c->id, 'from' => '2000-01-01', 'to' => today()->addYears(5)->toDateString()]) }}" class="font-medium flex-1 min-w-0 truncate hover:text-brand-ink">{{ $c->name }}</a>
                                <span class="text-xs text-muted">{{ $c->expenses_count }} {{ __('xərc') }}</span>
                                <span class="font-mono text-sm w-32 text-right">{{ money($c->expenses_sum_amount_azn ?? 0) }}</span>
                                @can('expenses.update')<button type="button" class="btn btn-ghost btn-icon btn-sm" @click="edit = true" aria-label="{{ __('Adını dəyiş') }}"><x-icon name="pencil" class="size-4"/></button>@endcan
                                @can('expenses.delete')
                                    <form method="POST" action="{{ route('expenses.categories.destroy', $c) }}" data-confirm="«{{ $c->name }}» silinsin?{{ $c->expenses_count ? ' '.$c->expenses_count.__(' xərc kateqoriyasız qalacaq.') : '' }}" data-confirm-action="{{ __('Sil') }}">
                                        @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                                    </form>
                                @endcan
                            </div>
                            @can('expenses.update')
                                <form method="POST" action="{{ route('expenses.categories.update', $c) }}" class="flex items-center gap-2" x-show="edit" x-cloak>
                                    @csrf @method('PUT')
                                    <input type="color" name="color" value="{{ $c->color ?: '#94a3b8' }}" class="size-9 rounded-lg border border-line bg-surface p-1" aria-label="{{ __('Rəng') }}">
                                    <input name="name" value="{{ $c->name }}" class="input flex-1" required aria-label="{{ __('Ad') }}">
                                    <button class="btn btn-primary btn-sm">{{ __('Saxla') }}</button>
                                    <button type="button" class="btn btn-ghost btn-sm" @click="edit = false">{{ __('Ləğv') }}</button>
                                </form>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @can('expenses.create')
            <form method="POST" action="{{ route('expenses.categories.store') }}" class="card p-5 space-y-4">
                @csrf
                <h2 class="text-sm font-semibold">{{ __('Yeni kateqoriya') }}</h2>
                <x-field :label="__('Ad')" name="name" required><input name="name" value="{{ old('name') }}" maxlength="120" class="input @error('name') is-invalid @enderror" placeholder="{{ __('Məs: İcarə') }}" required></x-field>
                <x-field :label="__('Rəng')" name="color"><input type="color" name="color" value="{{ old('color', '#0f9d82') }}" class="h-10 w-full rounded-lg border border-line bg-surface p-1"></x-field>
                <button class="btn btn-primary w-full"><x-icon name="plus" class="size-4"/> {{ __('Əlavə et') }}</button>
            </form>
        @endcan
    </div>
</x-layouts.app>
