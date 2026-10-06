<x-layouts.app title="CRM">
    <x-page-header title="CRM" icon="users" :subtitle="__('Müştərilər və təchizatçılar — müqavilələr yalnız burada olan kontragentlərlə bağlanır')">
        <x-slot:actions>
            @can('crm.import')
                <a href="{{ route('imports.index', ['type' => 'counterparties']) }}" class="btn btn-secondary"><x-icon name="upload" class="size-4"/> {{ __('Import') }}</a>
            @endcan
            @can('crm.create')
                <a href="{{ route('counterparties.create', ['type' => 'logistics']) }}" class="btn btn-secondary"><x-icon name="truck" class="size-4"/> {{ __('Logistika') }}</a>
                <a href="{{ route('counterparties.create', ['type' => 'supplier']) }}" class="btn btn-secondary"><x-icon name="building" class="size-4"/> {{ __('Təchizatçı') }}</a>
                <a href="{{ route('counterparties.create', ['type' => 'customer']) }}" class="btn btn-primary"><x-icon name="user-plus" class="size-4"/> {{ __('Müştəri') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- totals: who owes us, whom we owe — a click filters the list --}}
    <div class="grid sm:grid-cols-2 gap-4 mb-5">
        @foreach(['owes_us' => [__('Bizə borcludurlar'), 'text-success', 'arrow-down-left'], 'we_owe' => [__('Biz borcluyuq'), 'text-danger', 'arrow-up-right']] as $side => [$label, $tone, $icon])
            @php $on = request('balance') === $side; @endphp
            <a href="{{ route('counterparties.index', $on ? array_filter(request()->except('balance', 'page')) : array_merge(request()->except('page'), ['balance' => $side])) }}"
               @class(['card card-hover p-4 flex items-start gap-3', '!border-brand ring-2 ring-brand/20' => $on]) @if($on) aria-current="true" @endif>
                <span class="grid place-items-center size-10 rounded-xl bg-surface-2 {{ $tone }} shrink-0"><x-icon :name="$icon" class="size-5"/></span>
                <div class="min-w-0 flex-1">
                    <div class="text-xs text-muted">{{ $label }} · {{ $debts['count'][$side] }} {{ __('tərəf') }}</div>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 font-mono text-lg font-semibold {{ $tone }}">
                        @forelse($debts[$side] as $cur => $v)<span>{{ money($v, $cur) }}</span>@empty<span class="text-faint">—</span>@endforelse
                    </div>
                </div>
                <span class="text-[11px] text-muted shrink-0">{{ $on ? __('filtri sil') : __('göstər') }}</span>
            </a>
        @endforeach
    </div>

    <nav class="flex gap-6 border-b border-line mb-5 overflow-x-auto" aria-label="{{ __('Kontragent növü') }}">
        @foreach(['' => [__('Hamısı'), $counts['all']], 'customer' => [__('Müştərilər'), $counts['customer']], 'supplier' => [__('Təchizatçılar'), $counts['supplier']], 'logistics' => [__('Logistika şirkətləri'), $counts['logistics']]] as $type => [$label, $n])
            <a href="{{ route('counterparties.index', array_filter(['type' => $type])) }}" @class(['tab-link', 'is-active' => (string) request('type') === $type])>
                {{ $label }} <span class="ml-1 text-xs font-mono text-faint">{{ $n }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card overflow-hidden">
        <x-filter-bar :table="$table" export-route="counterparties.export" export-ability="crm.export" :placeholder="__('Ad, VÖEN, email, telefon, etiket…')"/>
        @if($items->isEmpty())
            <x-empty icon="users" :title="$table->hasActiveFilters() ? __('Heç nə tapılmadı') : __('Hələ kontragent yoxdur')"
                     :text="$table->hasActiveFilters() ? __('Filtrləri dəyişin və ya sıfırlayın.') : __('Müştəri və təchizatçılarınızı əlavə edin və ya Excel-dən import edin.')">
                @can('crm.create')<a href="{{ route('counterparties.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Kontragent əlavə et') }}</a>@endcan
            </x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead>
                    <tr>
                        <x-th :table="$table" sort="name">{{ __('Ad') }}</x-th>
                        <th>{{ __('Növ') }}</th>
                        <th>{{ __('VÖEN') }}</th>
                        <th>{{ __('Əlaqə') }}</th>
                        <x-th :table="$table" sort="contracts" num>{{ __('Müqavilələr') }}</x-th>
                        <th class="!text-right">{{ __('Balans') }}</th>
                        <th class="w-24"><span class="sr-only">{{ __('Əməliyyatlar') }}</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($items as $c)
                        <tr>
                            <td data-label="Ad">
                                <a href="{{ route('counterparties.show', $c) }}" class="flex items-center gap-3 group min-w-0">
                                    <span class="grid place-items-center size-9 shrink-0 rounded-lg {{ $c->isCustomer() ? 'bg-brand-soft text-brand' : 'bg-saffron-soft text-saffron' }}">
                                        <x-icon :name="$c->entity_type === 'individual' ? 'user' : 'building'" class="size-4"/>
                                    </span>
                                    <span class="min-w-0 text-left">
                                        <span class="block font-medium text-ink group-hover:text-brand-ink truncate max-w-[280px]">{{ $c->name }}</span>
                                        <span class="block text-xs text-muted truncate">{{ collect([$c->city, $c->country])->filter()->implode(', ') }}</span>
                                    </span>
                                </a>
                            </td>
                            <td data-label="{{ __('Növ') }}">
                                <span @class(['badge', 'badge-teal' => $c->type === 'customer', 'badge-amber' => $c->type === 'supplier', 'badge-violet' => $c->type === 'both', 'badge-blue' => $c->type === 'logistics'])>{{ $c->typeLabel() }}</span>
                            </td>
                            <td data-label="{{ __('VÖEN') }}" class="font-mono text-xs">{{ $c->voen ?? '—' }}</td>
                            <td data-label="{{ __('Əlaqə') }}" class="text-xs">
                                @if($contact = $c->contacts->first())
                                    <div class="text-ink-2">{{ $contact->name }}</div>
                                @endif
                                <div class="text-muted">{{ $c->phone ?? $c->email ?? '—' }}</div>
                            </td>
                            <td data-label="{{ __('Müqavilələr') }}" class="num">
                                <span class="text-ink">{{ $c->active_contracts_count }}</span><span class="text-faint"> / {{ $c->contracts_count }}</span>
                            </td>
                            <td data-label="{{ __('Balans') }}" class="num text-xs">
                                @forelse($balances[$c->id] ?? [] as $cur => $bal)
                                    <div @class(['font-semibold', 'text-success' => $bal > 0, 'text-danger' => $bal < 0]) title="{{ $bal > 0 ? __('Bizə borcludur') : __('Biz borcluyuq') }}">{{ $bal > 0 ? '+' : '−' }}{{ money(abs($bal), $cur) }}</div>
                                @empty
                                    <span class="text-faint">—</span>
                                @endforelse
                            </td>
                            <td data-label="" class="text-right whitespace-nowrap">
                                <a href="{{ route('counterparties.ledger', $c) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="{{ __('Hərəkətlər') }}" title="{{ __('Hərəkətlər') }}"><x-icon name="list" class="size-4"/></a>
                                @can('crm.update')
                                    <a href="{{ route('counterparties.edit', $c) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="{{ __('Redaktə et: :v1', ['v1' => $c->name]) }}"><x-icon name="pencil" class="size-4"/></a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        @endif
    </div>
</x-layouts.app>
