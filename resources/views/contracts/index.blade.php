<x-layouts.app :title="__('Müqavilələr')">
    <x-page-header :title="__('Müqavilələr')" icon="signature" :subtitle="__('Müştəri və təchizatçılarla bağlanan müqavilələr, ödəniş qrafikləri və müddətlər')">
        <x-slot:actions>
            @can('contracts.create')
                <a href="{{ route('contracts.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Yeni müqavilə') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid sm:grid-cols-3 gap-4 mb-6 stagger">
        <a href="{{ route('contracts.index', ['kind' => 'sale', 'status' => 'open']) }}" class="card card-hover p-5" style="--i:0">
            <div class="flex items-center gap-2 text-xs text-muted"><span class="size-2 rounded-full bg-brand"></span> {{ __('Açıq satış müqavilələri') }}</div>
            <div class="mt-2 text-2xl font-semibold font-mono">{{ money($summary['sale']->s ?? 0) }}</div>
            <div class="text-xs text-muted mt-1">{{ $summary['sale']->n ?? 0 }} {{ __('müqavilə · AZN ekvivalenti') }}</div>
        </a>
        <a href="{{ route('contracts.index', ['kind' => 'purchase', 'status' => 'open']) }}" class="card card-hover p-5" style="--i:1">
            <div class="flex items-center gap-2 text-xs text-muted"><span class="size-2 rounded-full bg-saffron"></span> {{ __('Açıq alış müqavilələri') }}</div>
            <div class="mt-2 text-2xl font-semibold font-mono">{{ money($summary['purchase']->s ?? 0) }}</div>
            <div class="text-xs text-muted mt-1">{{ $summary['purchase']->n ?? 0 }} {{ __('müqavilə · AZN ekvivalenti') }}</div>
        </a>
        <a href="{{ route('contracts.index', ['ending' => '30']) }}" class="card card-hover p-5" style="--i:2">
            <div class="flex items-center gap-2 text-xs text-muted"><span class="size-2 rounded-full bg-danger"></span> {{ __('30 gün ərzində bitir') }}</div>
            <div class="mt-2 text-2xl font-semibold font-mono">{{ $ending }}</div>
            <div class="text-xs text-muted mt-1">{{ __('yeniləmə və ya bağlanış tələb edir') }}</div>
        </a>
    </div>

    <div class="card overflow-hidden">
        <x-filter-bar :table="$table" export-route="contracts.export" export-ability="contracts.export" :placeholder="__('Nömrə, mövzu, kontragent, VÖEN…')"/>
        @if($items->isEmpty())
            <x-empty icon="signature" :title="$table->hasActiveFilters() ? __('Heç nə tapılmadı') : __('Hələ müqavilə yoxdur')"
                     :text="__('Müqavilə bağlamaq üçün əvvəlcə CRM-də müştəri və ya təchizatçı olmalıdır.')">
                @can('contracts.create')<a href="{{ route('contracts.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Müqavilə yarat') }}</a>@endcan
            </x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead>
                    <tr>
                        <x-th :table="$table" sort="number">{{ __('Nömrə') }}</x-th>
                        <th>{{ __('Kontragent') }}</th>
                        <th>{{ __('Mövzu') }}</th>
                        <x-th :table="$table" sort="end">{{ __('Müddət') }}</x-th>
                        <x-th :table="$table" sort="amount" num>{{ __('Məbləğ') }}</x-th>
                        <th>{{ __('Status') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($items as $c)
                        @php $left = $c->daysLeft(); $open = in_array($c->status, ['signed', 'active']); @endphp
                        <tr>
                            <td data-label="{{ __('Nömrə') }}">
                                <a href="{{ route('contracts.show', $c) }}" class="font-mono font-medium text-ink hover:text-brand-ink">{{ $c->number }}</a>
                                <div class="text-xs text-muted">{{ azdate($c->contract_date) }} · {{ $c->kind === 'sale' ? __('Satış') : __('Alış') }}</div>
                            </td>
                            <td data-label="{{ __('Kontragent') }}"><a href="{{ route('counterparties.show', $c->counterparty_id) }}" class="hover:text-brand-ink truncate block max-w-[220px]">{{ $c->counterparty?->name }}</a></td>
                            <td data-label="{{ __('Mövzu') }}" class="max-w-[280px]"><span class="block truncate">{{ $c->subject }}</span>@if($c->project)<span class="text-xs text-muted">{{ $c->project->code }}</span>@endif</td>
                            <td data-label="{{ __('Müddət') }}" class="text-xs">
                                <span class="font-mono">{{ azdate($c->end_date) }}</span>
                                @if($open && $left !== null)
                                    <div @class(['font-medium', 'text-danger' => $left <= 7, 'text-saffron' => $left > 7 && $left <= 30, 'text-muted' => $left > 30])>
                                        {{ $left < 0 ? abs($left).__(' gün keçib') : ($left === 0 ? __('bu gün bitir') : $left.__(' gün qalıb')) }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="{{ __('Məbləğ') }}" class="num">
                                <div class="text-ink">{{ money($c->amount, $c->currency) }}</div>
                                @if($c->currency !== 'AZN')<div class="text-xs text-muted">≈ {{ money($c->amount_azn) }}</div>@endif
                            </td>
                            <td data-label="Status"><x-status group="contract" :value="$c->status"/></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        @endif
    </div>
</x-layouts.app>
