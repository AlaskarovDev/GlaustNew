<x-layouts.app :title="__('Müqavilə ').$contract->number">
    @php
        $left = $contract->daysLeft();
        $pct = $contract->amount_azn > 0 ? min(100, round($settled / $contract->amount_azn * 100)) : 0;
        $scheduled = $contract->payments->sum('amount');
        $paid = $contract->payments->whereNotNull('paid_at')->sum('amount');
    @endphp
    <x-page-header :title="__('Müqavilə ').$contract->number" :subtitle="$contract->subject" :back="route('contracts.index')">
        <x-slot:actions>
            <a href="{{ route('contracts.pdf', $contract) }}" target="_blank" class="btn btn-secondary"><x-icon name="printer" class="size-4"/> PDF</a>
            @can('contracts.create')
                <a href="{{ route('contracts.create', ['parent_id' => $contract->id]) }}" class="btn btn-secondary"><x-icon name="plus" class="size-4"/> {{ __('Əlavə razılaşma') }}</a>
            @endcan
            @can('contracts.update')
                <a href="{{ route('contracts.edit', $contract) }}" class="btn btn-primary"><x-icon name="pencil" class="size-4"/> {{ __('Redaktə') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
        <x-status group="contract" :value="$contract->status"/>
        <span class="badge {{ $contract->kind === 'sale' ? 'badge-teal' : 'badge-amber' }}">{{ $contract->kind === 'sale' ? __('Satış müqaviləsi') : __('Alış müqaviləsi') }}</span>
        @if($contract->auto_renew)<span class="badge badge-blue"><x-icon name="refresh" class="size-3"/> {{ __('Avtomatik uzadılır') }}</span>@endif
        @if($contract->parent)<a href="{{ route('contracts.show', $contract->parent) }}" class="badge badge-violet">{{ __('Əsas müqavilə:') }} {{ $contract->parent->number }}</a>@endif
        @if(in_array($contract->status, ['signed', 'active']) && $left !== null)
            <span @class(['badge', 'badge-rose' => $left <= 7, 'badge-amber' => $left > 7 && $left <= 30, 'badge-slate' => $left > 30])>
                <x-icon name="clock" class="size-3"/> {{ $left < 0 ? __('müddəti ').abs($left).__(' gün əvvəl bitib') : ($left === 0 ? __('bu gün bitir') : $left.__(' gün qalıb')) }}
            </span>
        @endif
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <div class="grid sm:grid-cols-3 gap-6">
                    <div>
                        <div class="text-xs text-muted">{{ __('Müqavilə məbləği') }}</div>
                        <div class="mt-1 text-2xl font-semibold font-mono">{{ money($contract->amount, $contract->currency) }}</div>
                        @if($contract->currency !== 'AZN')
                            <div class="mt-1 text-xs text-muted">≈ {{ money($contract->amount_azn) }} · CBAR {{ rate_fmt($contract->cbar_rate) }} ({{ azdate($contract->rate_date) }})</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs text-muted">{{ $contract->kind === 'sale' ? __('Daxil olub') : __('Ödənilib') }} (bank)</div>
                        <div class="mt-1 text-2xl font-semibold font-mono text-success">{{ money($settled) }}</div>
                        <div class="mt-1 text-xs text-muted">{{ __('Qalıq ≈') }} {{ money(max(0, $contract->amount_azn - $settled)) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted">{{ __('İcra') }}</div>
                        <div class="mt-1 text-2xl font-semibold font-mono">{{ $pct }}%</div>
                        <div class="mt-2 h-2 rounded-full bg-surface-2 overflow-hidden"><div class="h-full rounded-full bg-brand rise" style="width: {{ $pct }}%"></div></div>
                    </div>
                </div>
                <dl class="mt-6 pt-6 border-t border-line grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Müqavilə tarixi') }}</dt><dd class="font-mono">{{ azdate($contract->contract_date) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Müddət') }}</dt><dd class="font-mono">{{ azdate($contract->start_date) }} — {{ azdate($contract->end_date) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Layihə') }}</dt><dd>@if($contract->project)<a href="{{ route('projects.show', $contract->project) }}" class="text-brand-ink hover:underline">{{ $contract->project->code }} · {{ $contract->project->name }}</a>@else — @endif</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Məsul şəxs') }}</dt><dd>{{ $contract->responsible?->name ?? '—' }}</dd></div>
                    @if($contract->payment_terms)<div class="sm:col-span-2 flex justify-between gap-4"><dt class="text-muted shrink-0">{{ __('Ödəniş şərtləri') }}</dt><dd class="text-right">{{ $contract->payment_terms }}</dd></div>@endif
                </dl>
                @if($contract->kind === 'service' && $contract->service_terms)
                    @php $st = $contract->service_terms; $na = fn ($v) => $v === null || $v === '' ? '—' : $v; @endphp
                    <div class="mt-5 pt-5 border-t border-line">
                        <h3 class="text-sm font-semibold flex items-center gap-2 mb-3"><x-icon name="truck" class="size-4 text-saffron"/> {{ __('Logistika xidmətinin şərtləri') }}</h3>
                        <dl class="grid sm:grid-cols-2 gap-x-8 gap-y-2.5 text-sm">
                            <div class="sm:col-span-2 flex justify-between gap-4"><dt class="text-muted">{{ __('Marşrut') }}</dt><dd class="text-right">{{ $na($st['route_from'] ?? null) }} → {{ $na($st['route_to'] ?? null) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Nəqliyyat növü') }}</dt><dd>{{ config('glaust.transport_modes.'.($st['transport_mode'] ?? ''), '—') }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Tarif') }}</dt><dd class="font-mono">@if(isset($st['tariff']) && $st['tariff'] !== null){{ money($st['tariff'], $st['tariff_currency'] ?? 'EUR') }} / {{ config('glaust.tariff_units.'.($st['tariff_unit'] ?? ''), '') }}@else — @endif</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Çatdırılma müddəti') }}</dt><dd class="font-mono">{{ isset($st['transit_days']) && $st['transit_days'] !== null && $st['transit_days'] !== '' ? $st['transit_days'].' '.__('gün') : '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Maks. yük') }}</dt><dd class="font-mono">{{ ! empty($st['max_weight']) ? num($st['max_weight'], 0).' kq' : '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Ödəniş') }}</dt><dd>{{ isset($st['payment_days']) && $st['payment_days'] !== null && $st['payment_days'] !== '' ? $st['payment_days'].' '.__('gün') : '' }} {{ config('glaust.payment_bases.'.($st['payment_basis'] ?? ''), '') }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Sığorta / gömrük') }}</dt><dd>{{ ! empty($st['insurance']) ? __('sığorta daxil') : __('sığorta daxil deyil') }} · {{ ! empty($st['customs']) ? __('gömrük daxil') : __('gömrük daxil deyil') }}</dd></div>
                        </dl>
                    </div>
                @endif
            </section>

            <section class="card overflow-hidden">
                <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                    <h2 class="text-sm font-semibold">{{ __('Ödəniş qrafiki') }}</h2>
                    @if($contract->payments->isNotEmpty())
                        <span class="text-xs text-muted font-mono">{{ money($paid, $contract->currency) }} / {{ money($scheduled, $contract->currency) }}</span>
                    @endif
                </header>
                @if($contract->payments->isEmpty())
                    <p class="px-5 py-6 text-sm text-muted">{{ __('Ödəniş qrafiki yoxdur.') }}</p>
                @else
                    <ol class="divide-y divide-line">
                        @foreach($contract->payments as $i => $p)
                            @php $late = ! $p->paid_at && $p->due_date->lt(today()); @endphp
                            <li class="flex items-center gap-4 px-5 py-3">
                                <span @class(['grid place-items-center size-8 rounded-full text-xs font-semibold font-mono shrink-0', 'bg-success-soft text-success' => $p->paid_at, 'bg-danger-soft text-danger' => $late, 'bg-surface-2 text-muted' => ! $p->paid_at && ! $late])>
                                    @if($p->paid_at)<x-icon name="check" class="size-4"/>@else{{ $i + 1 }}@endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium font-mono">{{ money($p->amount, $contract->currency) }}</div>
                                    <div class="text-xs text-muted">{{ $p->note ?? __('Mərhələ ').($i + 1) }} · {{ azdate($p->due_date) }}
                                        @if($late)<span class="text-danger font-medium"> · {{ (int) $p->due_date->diffInDays(today()) }} {{ __('gün gecikir') }}</span>@endif
                                        @if($p->paid_at)<span class="text-success"> {{ __('· ödənilib') }} {{ azdate($p->paid_at) }}</span>@endif
                                    </div>
                                </div>
                                @can('contracts.update')
                                    <form method="POST" action="{{ route('contracts.payments.toggle', [$contract, $p]) }}">@csrf
                                        <button class="btn btn-secondary btn-sm">{{ $p->paid_at ? __('Geri al') : __('Ödənildi') }}</button>
                                    </form>
                                @endcan
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            @can('bank.view')
                <section class="card overflow-hidden">
                    <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                        <h2 class="text-sm font-semibold">{{ __('Bank əməliyyatları') }} <span class="text-muted font-mono font-normal">{{ $transactions->count() }}</span></h2>
                        @can('bank.create')
                            <a href="{{ route('bank.transactions.create', ['contract_id' => $contract->id, 'counterparty_id' => $contract->counterparty_id, 'direction' => $contract->kind === 'sale' ? 'in' : 'out']) }}" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4"/> {{ __('Əməliyyat') }}</a>
                        @endcan
                    </header>
                    @if($transactions->isEmpty())
                        <p class="px-5 py-6 text-sm text-muted">{{ __('Bu müqavilə üzrə əməliyyat yoxdur.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                        <table class="table-g table-stack">
                            <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Hesab') }}</th><th>{{ __('Təyinat') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th class="!text-right">AZN</th></tr></thead>
                            <tbody>
                            @foreach($transactions as $t)
                                <tr>
                                    <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($t->transaction_date) }}</td>
                                    <td data-label="{{ __('Hesab') }}" class="text-xs">{{ $t->account?->name }}</td>
                                    <td data-label="{{ __('Təyinat') }}"><a href="{{ route('bank.transactions.show', $t) }}" class="hover:text-brand-ink">{{ $t->purpose ?? '—' }}</a></td>
                                    <td data-label="{{ __('Məbləğ') }}" class="num {{ $t->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ $t->direction === 'in' ? '+' : '−' }}{{ money($t->amount, $t->currency) }}</td>
                                    <td data-label="AZN" class="num">{{ money($t->amount_azn) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        </div>
                    @endif
                </section>
            @endcan

            @if($contract->amendments->isNotEmpty())
                <section class="card overflow-hidden">
                    <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">{{ __('Əlavə razılaşmalar') }}</h2></header>
                    <ul class="divide-y divide-line">
                        @foreach($contract->amendments as $a)
                            <li><a href="{{ route('contracts.show', $a) }}" class="flex items-center gap-4 px-5 py-3 hover:bg-surface-2 text-sm">
                                <span class="font-mono">{{ $a->number }}</span><span class="flex-1 truncate text-muted">{{ $a->subject }}</span>
                                <span class="font-mono text-xs">{{ azdate($a->contract_date) }}</span><x-status group="contract" :value="$a->status"/>
                            </a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @include('partials.history', ['history' => $history])
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-5">
                <div class="text-xs text-muted mb-2">{{ $contract->kind === 'sale' ? __('Müştəri') : __('Təchizatçı') }}</div>
                <a href="{{ route('counterparties.show', $contract->counterparty) }}" class="flex items-center gap-3 group">
                    <span class="grid place-items-center size-10 rounded-xl bg-brand-soft text-brand"><x-icon name="building" class="size-5"/></span>
                    <span class="min-w-0">
                        <span class="block font-semibold group-hover:text-brand-ink truncate">{{ $contract->counterparty->name }}</span>
                        <span class="block text-xs text-muted font-mono">{{ $contract->counterparty->voen ? __('VÖEN ').$contract->counterparty->voen : '' }}</span>
                    </span>
                </a>
                @if($contract->counterparty->trashed())<p class="mt-2 text-xs text-danger">{{ __('Bu kontragent CRM-dən silinib.') }}</p>@endif
            </section>
            @if($contract->notes)
                <section class="card p-5"><h2 class="text-sm font-semibold mb-2">{{ __('Qeydlər') }}</h2><p class="text-sm text-ink-2 whitespace-pre-line">{{ $contract->notes }}</p></section>
            @endif
            @include('partials.attachments', ['model' => $contract, 'type' => 'contract', 'ability' => 'contracts.update'])
            @can('contracts.delete')
                <x-delete-form :action="route('contracts.destroy', $contract)" :label="__('Müqaviləni sil')" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="__('Müqavilə ').$contract->number.__(' silinəcək.')"/>
            @endcan
        </aside>
    </div>
</x-layouts.app>
