<x-layouts.app title="CRM">
    <x-page-header title="CRM" icon="users" subtitle="Müştərilər və təchizatçılar — müqavilələr yalnız burada olan kontragentlərlə bağlanır">
        <x-slot:actions>
            @can('crm.import')
                <a href="{{ route('imports.index', ['type' => 'counterparties']) }}" class="btn btn-secondary"><x-icon name="upload" class="size-4"/> Import</a>
            @endcan
            @can('crm.create')
                <a href="{{ route('counterparties.create', ['type' => 'supplier']) }}" class="btn btn-secondary"><x-icon name="building" class="size-4"/> Təchizatçı</a>
                <a href="{{ route('counterparties.create', ['type' => 'customer']) }}" class="btn btn-primary"><x-icon name="user-plus" class="size-4"/> Müştəri</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <nav class="flex gap-6 border-b border-line mb-5 overflow-x-auto" aria-label="Kontragent növü">
        @foreach(['' => ['Hamısı', $counts['all']], 'customer' => ['Müştərilər', $counts['customer']], 'supplier' => ['Təchizatçılar', $counts['supplier']]] as $type => [$label, $n])
            <a href="{{ route('counterparties.index', array_filter(['type' => $type])) }}" @class(['tab-link', 'is-active' => (string) request('type') === $type])>
                {{ $label }} <span class="ml-1 text-xs font-mono text-faint">{{ $n }}</span>
            </a>
        @endforeach
    </nav>

    <div class="card overflow-hidden">
        <x-filter-bar :table="$table" export-route="counterparties.export" export-ability="crm.export" placeholder="Ad, VÖEN, email, telefon, etiket…"/>
        @if($items->isEmpty())
            <x-empty icon="users" :title="$table->hasActiveFilters() ? 'Heç nə tapılmadı' : 'Hələ kontragent yoxdur'"
                     :text="$table->hasActiveFilters() ? 'Filtrləri dəyişin və ya sıfırlayın.' : 'Müştəri və təchizatçılarınızı əlavə edin və ya Excel-dən import edin.'">
                @can('crm.create')<a href="{{ route('counterparties.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> Kontragent əlavə et</a>@endcan
            </x-empty>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead>
                    <tr>
                        <x-th :table="$table" sort="name">Ad</x-th>
                        <th>Növ</th>
                        <th>VÖEN</th>
                        <th>Əlaqə</th>
                        <x-th :table="$table" sort="contracts" num>Müqavilələr</x-th>
                        <th class="w-10"><span class="sr-only">Əməliyyatlar</span></th>
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
                            <td data-label="Növ">
                                <span @class(['badge', 'badge-teal' => $c->type === 'customer', 'badge-amber' => $c->type === 'supplier', 'badge-violet' => $c->type === 'both'])>{{ $c->typeLabel() }}</span>
                            </td>
                            <td data-label="VÖEN" class="font-mono text-xs">{{ $c->voen ?? '—' }}</td>
                            <td data-label="Əlaqə" class="text-xs">
                                @if($contact = $c->contacts->first())
                                    <div class="text-ink-2">{{ $contact->name }}</div>
                                @endif
                                <div class="text-muted">{{ $c->phone ?? $c->email ?? '—' }}</div>
                            </td>
                            <td data-label="Müqavilələr" class="num">
                                <span class="text-ink">{{ $c->active_contracts_count }}</span><span class="text-faint"> / {{ $c->contracts_count }}</span>
                            </td>
                            <td data-label="" class="text-right">
                                @can('crm.update')
                                    <a href="{{ route('counterparties.edit', $c) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="Redaktə et: {{ $c->name }}"><x-icon name="pencil" class="size-4"/></a>
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
