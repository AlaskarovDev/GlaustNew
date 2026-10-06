<x-layouts.app :title="$counterparty->name">
    @php
        $in = (float) ($turnover['in']->s ?? 0); $out = (float) ($turnover['out']->s ?? 0);
        $activeContracts = $contracts->whereIn('status', ['signed', 'active']);
    @endphp
    <x-page-header :title="$counterparty->name" :back="route('counterparties.index')">
        <x-slot:actions>
            <a href="{{ route('counterparties.ledger', $counterparty) }}" class="btn btn-secondary"><x-icon name="list" class="size-4"/> {{ __('Hərəkətlər') }}</a>
            @can('contracts.create')
                <a href="{{ route('contracts.create', ['counterparty_id' => $counterparty->id]) }}" class="btn btn-secondary"><x-icon name="signature" class="size-4"/> {{ __('Müqavilə bağla') }}</a>
            @endcan
            @can('crm.update')
                <a href="{{ route('counterparties.edit', $counterparty) }}" class="btn btn-secondary"><x-icon name="pencil" class="size-4"/> {{ __('Redaktə') }}</a>
            @endcan
            @can('crm.delete')
                <x-delete-form :action="route('counterparties.destroy', $counterparty)" button="btn btn-secondary text-danger" :message="'«'.$counterparty->name.__('» silinəcək. Açıq müqaviləsi varsa silinməyəcək.')"/>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
        <span @class(['badge', 'badge-teal' => $counterparty->type === 'customer', 'badge-amber' => $counterparty->type === 'supplier', 'badge-violet' => $counterparty->type === 'both', 'badge-blue' => $counterparty->type === 'logistics'])>{{ $counterparty->typeLabel() }}</span>
        <span class="badge badge-slate">{{ config('glaust.entity_types.'.$counterparty->entity_type) }}</span>
        @if($counterparty->voen)<span class="badge badge-slate font-mono">{{ __('VÖEN') }} {{ $counterparty->voen }}</span>@endif
        @foreach($counterparty->tagList() as $tag)<span class="badge badge-blue">#{{ $tag }}</span>@endforeach
    </div>

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
        <div class="card p-5" style="--i:0"><div class="text-xs text-muted">{{ __('Aktiv müqavilələr') }}</div><div class="mt-1 text-2xl font-semibold font-mono">{{ $activeContracts->count() }}</div><div class="text-xs text-muted mt-1">≈ {{ money($activeContracts->sum('amount_azn')) }}</div></div>
        <div class="card p-5" style="--i:1"><div class="text-xs text-muted">{{ __('Daxilolma (bütün dövr)') }}</div><div class="mt-1 text-2xl font-semibold font-mono text-success">{{ money($in) }}</div><div class="text-xs text-muted mt-1">{{ $turnover['in']->n ?? 0 }} {{ __('əməliyyat') }}</div></div>
        <div class="card p-5" style="--i:2"><div class="text-xs text-muted">{{ __('Məxaric (bütün dövr)') }}</div><div class="mt-1 text-2xl font-semibold font-mono text-danger">{{ money($out) }}</div><div class="text-xs text-muted mt-1">{{ $turnover['out']->n ?? 0 }} {{ __('əməliyyat') }}</div></div>
        <div class="card p-5" style="--i:3"><div class="text-xs text-muted">{{ __('Ümumi dövriyyə') }}</div><div class="mt-1 text-2xl font-semibold font-mono">{{ money($in + $out) }}</div><div class="text-xs text-muted mt-1">Saldo: {{ money($in - $out) }}</div></div>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card overflow-hidden">
                <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                    <h2 class="text-sm font-semibold">{{ __('Müqavilələr') }} <span class="text-muted font-mono font-normal">{{ $contracts->count() }}</span></h2>
                </header>
                @if($contracts->isEmpty())
                    <x-empty icon="signature" :title="__('Müqavilə yoxdur')" class="!py-10">
                        @can('contracts.create')<a href="{{ route('contracts.create', ['counterparty_id' => $counterparty->id]) }}" class="btn btn-primary btn-sm">{{ __('Müqavilə bağla') }}</a>@endcan
                    </x-empty>
                @else
                    <div class="overflow-x-auto">
                    <table class="table-g table-stack">
                        <thead><tr><th>{{ __('Nömrə') }}</th><th>{{ __('Növ') }}</th><th>{{ __('Mövzu') }}</th><th>{{ __('Bitmə') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach($contracts as $c)
                            <tr>
                                <td data-label="{{ __('Nömrə') }}"><a href="{{ route('contracts.show', $c) }}" class="font-mono font-medium text-ink hover:text-brand-ink">{{ $c->number }}</a></td>
                                <td data-label="{{ __('Növ') }}" class="text-xs">{{ $c->kind === 'sale' ? __('Satış') : __('Alış') }}</td>
                                <td data-label="{{ __('Mövzu') }}" class="max-w-[260px] truncate">{{ $c->subject }}</td>
                                <td data-label="{{ __('Bitmə') }}" class="font-mono text-xs">{{ azdate($c->end_date) }}</td>
                                <td data-label="{{ __('Məbləğ') }}" class="num">{{ money($c->amount, $c->currency) }}</td>
                                <td data-label="Status"><x-status group="contract" :value="$c->status"/></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </section>

            @if($projects->isNotEmpty())
                <section class="card overflow-hidden">
                    <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">{{ __('Layihələr') }} <span class="text-muted font-mono font-normal">{{ $projects->count() }}</span></h2></header>
                    <ul class="divide-y divide-line">
                        @foreach($projects as $p)
                            <li><a href="{{ route('projects.show', $p) }}" class="flex items-center gap-4 px-5 py-3 hover:bg-surface-2">
                                <span class="font-mono text-xs text-muted w-24 shrink-0">{{ $p->code }}</span>
                                <span class="flex-1 min-w-0 text-sm font-medium truncate">{{ $p->name }}</span>
                                <span class="w-24 h-1.5 rounded-full bg-surface-2 overflow-hidden hidden sm:block"><span class="block h-full bg-brand" style="width: {{ $p->progress() }}%"></span></span>
                                <x-status group="project" :value="$p->status"/>
                            </a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @can('bank.view')
                <section class="card overflow-hidden">
                    <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                        <h2 class="text-sm font-semibold">{{ __('Son bank əməliyyatları') }}</h2>
                        <a href="{{ route('bank.transactions.index', ['counterparty_id' => $counterparty->id]) }}" class="text-xs font-medium text-brand-ink hover:underline">{{ __('Hamısı') }}</a>
                    </header>
                    @if($transactions->isEmpty())
                        <p class="px-5 py-6 text-sm text-muted">{{ __('Əməliyyat yoxdur.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                        <table class="table-g table-stack">
                            <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Hesab') }}</th><th>{{ __('Təyinat') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th class="!text-right">AZN</th></tr></thead>
                            <tbody>
                            @foreach($transactions as $t)
                                <tr>
                                    <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($t->transaction_date) }}</td>
                                    <td data-label="{{ __('Hesab') }}" class="text-xs">{{ $t->account?->name }}</td>
                                    <td data-label="{{ __('Təyinat') }}" class="max-w-[240px] truncate"><a href="{{ route('bank.transactions.show', $t) }}" class="hover:text-brand-ink">{{ $t->purpose ?? '—' }}</a></td>
                                    <td data-label="{{ __('Məbləğ') }}" class="num {{ $t->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ $t->direction === 'in' ? '+' : '−' }}{{ money($t->amount, $t->currency) }}</td>
                                    <td data-label="AZN" class="num text-muted">{{ money($t->amount_azn) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </section>
            @endcan

            @if($shipments->isNotEmpty())
                <section class="card overflow-hidden">
                    <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">{{ __('Daşıdığı yüklər') }}</h2></header>
                    <ul class="divide-y divide-line">
                        @foreach($shipments as $s)
                            <li><a href="{{ route('shipments.show', $s) }}" class="flex items-center gap-4 px-5 py-3 hover:bg-surface-2 text-sm">
                                <span class="font-mono text-xs w-28 shrink-0">{{ $s->number }}</span>
                                <span class="flex-1 truncate">{{ $s->origin }} → {{ $s->destination }}</span>
                                <x-status group="shipment" :value="$s->status"/>
                            </a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @include('partials.history', ['history' => $history])
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-5 text-sm space-y-3">
                <h2 class="font-semibold">{{ __('Rekvizitlər') }}</h2>
                @foreach([['map-pin', collect([$counterparty->address, $counterparty->city, $counterparty->country])->filter()->implode(', ')], ['phone', $counterparty->phone], ['mail', $counterparty->email], ['globe', $counterparty->website]] as [$icon, $val])
                    @if($val)
                        <div class="flex gap-2.5"><x-icon :name="$icon" class="size-4 text-faint mt-0.5 shrink-0"/><span class="break-words min-w-0">{{ $val }}</span></div>
                    @endif
                @endforeach
                @if($counterparty->iban)
                    <div class="pt-3 border-t border-line space-y-1">
                        <div class="text-xs text-muted">IBAN</div>
                        <div class="font-mono text-xs break-all">{{ trim(chunk_split($counterparty->iban, 4, ' ')) }}</div>
                        <div class="text-xs text-muted">{{ $counterparty->bank_name }} {{ $counterparty->swift ? '· '.$counterparty->swift : '' }}</div>
                    </div>
                @endif
            </section>

            <section class="card p-5">
                <h2 class="text-sm font-semibold mb-3">{{ __('Əlaqə şəxsləri') }}</h2>
                @forelse($counterparty->contacts as $p)
                    <div class="flex items-start gap-3 py-2.5 border-t border-line first:border-0 first:pt-0">
                        <span class="grid place-items-center size-8 rounded-full bg-surface-2 text-xs font-semibold text-muted shrink-0">{{ mb_substr($p->name, 0, 1) }}</span>
                        <div class="min-w-0 text-sm">
                            <div class="font-medium">{{ $p->name }}</div>
                            @if($p->position)<div class="text-xs text-muted">{{ $p->position }}</div>@endif
                            @if($p->phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $p->phone) }}" class="block text-xs text-brand-ink">{{ $p->phone }}</a>@endif
                            @if($p->email)<a href="mailto:{{ $p->email }}" class="block text-xs text-brand-ink truncate">{{ $p->email }}</a>@endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('Əlavə edilməyib.') }}</p>
                @endforelse
            </section>

            @if($counterparty->notes)
                <section class="card p-5"><h2 class="text-sm font-semibold mb-2">{{ __('Qeydlər') }}</h2><p class="text-sm text-ink-2 whitespace-pre-line">{{ $counterparty->notes }}</p></section>
            @endif

            @include('partials.attachments', ['model' => $counterparty, 'type' => 'counterparty', 'ability' => 'crm.update'])
        </aside>
    </div>
</x-layouts.app>
