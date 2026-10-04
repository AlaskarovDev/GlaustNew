<x-layouts.app :title="__('Logistika')">
    @php $modeIcons = ['road' => 'truck', 'rail' => 'train', 'sea' => 'ship', 'air' => 'plane']; @endphp
    <x-page-header :title="__('Logistika əməliyyatları')" icon="truck" :subtitle="__('Yüklər, daşıyıcılar, statuslar və logistika xərcləri')">
        <x-slot:actions>
            @can('logistics.import')
                <a href="{{ route('imports.index', ['type' => 'shipments']) }}" class="btn btn-secondary"><x-icon name="upload" class="size-4"/> {{ __('Import') }}</a>
            @endcan
            @can('logistics.create')
                <a href="{{ route('shipments.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Yeni yük') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Status pipeline --}}
    <div class="grid grid-cols-3 md:grid-cols-6 gap-2 mb-6 stagger">
        @foreach(config('glaust.statuses.shipment') as $key => [$label, $color])
            <a href="{{ route('shipments.index', ['status' => request('status') === $key ? null : $key]) }}" style="--i:{{ $loop->index }}"
               @class(['card card-hover px-4 py-3 relative', '!border-brand ring-1 ring-brand/30' => request('status') === $key])>
                <div class="text-[11px] text-muted truncate">{{ $label }}</div>
                <div class="text-xl font-semibold font-mono">{{ $counts[$key] ?? 0 }}</div>
                <span class="absolute top-3 right-3 size-2 rounded-full badge-{{ $color }} !bg-current"></span>
            </a>
        @endforeach
    </div>
    @if($delayed)
        <a href="{{ route('shipments.index', ['status' => 'delayed']) }}" class="flex items-center gap-3 card border-danger/30 bg-danger-soft/40 px-4 py-3 mb-5 text-sm hover:bg-danger-soft/70 transition-colors">
            <x-icon name="alert" class="size-5 text-danger"/><span><b>{{ $delayed }}</b> {{ __('yük gözlənilən çatma tarixini keçib.') }}</span><x-icon name="arrow-right" class="size-4 ml-auto text-danger"/>
        </a>
    @endif

    <div class="card overflow-hidden">
        <x-filter-bar :table="$table" export-route="shipments.export" export-ability="logistics.export" :placeholder="__('Nömrə, marşrut, konteyner, CMR, daşıyıcı…')"/>
        @if($items->isEmpty())
            <x-empty icon="package" :title="$table->hasActiveFilters() ? __('Heç nə tapılmadı') : __('Hələ yük yoxdur')" :text="__('Yükləri qeyd edin, statusu dəyişdikcə tarixçə avtomatik saxlanılır.')">
                @can('logistics.create')<a href="{{ route('shipments.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Yük əlavə et') }}</a>@endcan
            </x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr>
                        <x-th :table="$table" sort="number">{{ __('Nömrə') }}</x-th><th>{{ __('Marşrut') }}</th><th>{{ __('Daşıyıcı') }}</th>
                        <x-th :table="$table" sort="eta">{{ __('Çatma') }}</x-th><th class="!text-right">{{ __('Xərclər') }}</th><th>{{ __('Status') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($items as $s)
                        <tr>
                            <td data-label="{{ __('Nömrə') }}">
                                <a href="{{ route('shipments.show', $s) }}" class="inline-flex items-center gap-2 font-mono font-medium text-ink hover:text-brand-ink">
                                    <span class="grid place-items-center size-8 rounded-lg bg-surface-2 text-muted"><x-icon :name="$modeIcons[$s->transport_mode] ?? 'truck'" class="size-4"/></span>{{ $s->number }}
                                </a>
                            </td>
                            <td data-label="{{ __('Marşrut') }}">
                                <div class="flex items-center gap-1.5 text-sm"><span class="truncate max-w-[130px]">{{ $s->origin }}</span><x-icon name="arrow-right" class="size-3.5 text-faint shrink-0"/><span class="truncate max-w-[130px]">{{ $s->destination }}</span></div>
                                <div class="text-xs text-muted">{{ config('glaust.shipment_directions.'.$s->direction) }}{{ $s->container_no ? ' · '.$s->container_no : '' }}</div>
                            </td>
                            <td data-label="{{ __('Daşıyıcı') }}" class="text-sm">{{ $s->carrier?->name ?? '—' }}</td>
                            <td data-label="{{ __('Çatma') }}" class="text-xs">
                                <span @class(['font-mono', 'text-danger font-medium' => $s->isDelayed()])>{{ azdate($s->eta) }}</span>
                                @if($s->isDelayed())<div class="text-danger">{{ (int) $s->eta->diffInDays(today()) }} {{ __('gün gecikir') }}</div>@endif
                            </td>
                            <td data-label="{{ __('Xərclər') }}" class="num">{{ $s->costs_sum_amount_azn ? money($s->costs_sum_amount_azn) : '—' }}</td>
                            <td data-label="Status"><x-status group="shipment" :value="$s->status"/></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        @endif
    </div>
</x-layouts.app>
