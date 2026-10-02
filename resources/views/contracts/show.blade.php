<x-layouts.app :title="'Müqavilə '.$contract->number">
    @php
        $left = $contract->daysLeft();
        $pct = $contract->amount_azn > 0 ? min(100, round($settled / $contract->amount_azn * 100)) : 0;
        $scheduled = $contract->payments->sum('amount');
        $paid = $contract->payments->whereNotNull('paid_at')->sum('amount');
    @endphp
    <x-page-header :title="'Müqavilə '.$contract->number" :subtitle="$contract->subject" :back="route('contracts.index')">
        <x-slot:actions>
            <a href="{{ route('contracts.pdf', $contract) }}" target="_blank" class="btn btn-secondary"><x-icon name="printer" class="size-4"/> PDF</a>
            @can('contracts.create')
                <a href="{{ route('contracts.create', ['parent_id' => $contract->id]) }}" class="btn btn-secondary"><x-icon name="plus" class="size-4"/> Əlavə razılaşma</a>
            @endcan
            @can('contracts.update')
                <a href="{{ route('contracts.edit', $contract) }}" class="btn btn-primary"><x-icon name="pencil" class="size-4"/> Redaktə</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2 -mt-4 mb-6">
        <x-status group="contract" :value="$contract->status"/>
        <span class="badge {{ $contract->kind === 'sale' ? 'badge-teal' : 'badge-amber' }}">{{ $contract->kind === 'sale' ? 'Satış müqaviləsi' : 'Alış müqaviləsi' }}</span>
        @if($contract->auto_renew)<span class="badge badge-blue"><x-icon name="refresh" class="size-3"/> Avtomatik uzadılır</span>@endif
        @if($contract->parent)<a href="{{ route('contracts.show', $contract->parent) }}" class="badge badge-violet">Əsas müqavilə: {{ $contract->parent->number }}</a>@endif
        @if(in_array($contract->status, ['signed', 'active']) && $left !== null)
            <span @class(['badge', 'badge-rose' => $left <= 7, 'badge-amber' => $left > 7 && $left <= 30, 'badge-slate' => $left > 30])>
                <x-icon name="clock" class="size-3"/> {{ $left < 0 ? 'müddəti '.abs($left).' gün əvvəl bitib' : ($left === 0 ? 'bu gün bitir' : $left.' gün qalıb') }}
            </span>
        @endif
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <div class="grid sm:grid-cols-3 gap-6">
                    <div>
                        <div class="text-xs text-muted">Müqavilə məbləği</div>
                        <div class="mt-1 text-2xl font-semibold font-mono">{{ money($contract->amount, $contract->currency) }}</div>
                        @if($contract->currency !== 'AZN')
                            <div class="mt-1 text-xs text-muted">≈ {{ money($contract->amount_azn) }} · CBAR {{ rate_fmt($contract->cbar_rate) }} ({{ azdate($contract->rate_date) }})</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs text-muted">{{ $contract->kind === 'sale' ? 'Daxil olub' : 'Ödənilib' }} (bank)</div>
                        <div class="mt-1 text-2xl font-semibold font-mono text-success">{{ money($settled) }}</div>
                        <div class="mt-1 text-xs text-muted">Qalıq ≈ {{ money(max(0, $contract->amount_azn - $settled)) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted">İcra</div>
                        <div class="mt-1 text-2xl font-semibold font-mono">{{ $pct }}%</div>
                        <div class="mt-2 h-2 rounded-full bg-surface-2 overflow-hidden"><div class="h-full rounded-full bg-brand rise" style="width: {{ $pct }}%"></div></div>
                    </div>
                </div>
                <dl class="mt-6 pt-6 border-t border-line grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">Müqavilə tarixi</dt><dd class="font-mono">{{ azdate($contract->contract_date) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Müddət</dt><dd class="font-mono">{{ azdate($contract->start_date) }} — {{ azdate($contract->end_date) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Layihə</dt><dd>@if($contract->project)<a href="{{ route('projects.show', $contract->project) }}" class="text-brand-ink hover:underline">{{ $contract->project->code }} · {{ $contract->project->name }}</a>@else — @endif</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Məsul şəxs</dt><dd>{{ $contract->responsible?->name ?? '—' }}</dd></div>
                    @if($contract->payment_terms)<div class="sm:col-span-2 flex justify-between gap-4"><dt class="text-muted shrink-0">Ödəniş şərtləri</dt><dd class="text-right">{{ $contract->payment_terms }}</dd></div>@endif
                </dl>
            </section>

            <section class="card overflow-hidden">
                <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                    <h2 class="text-sm font-semibold">Ödəniş qrafiki</h2>
                    @if($contract->payments->isNotEmpty())
                        <span class="text-xs text-muted font-mono">{{ money($paid, $contract->currency) }} / {{ money($scheduled, $contract->currency) }}</span>
                    @endif
                </header>
                @if($contract->payments->isEmpty())
                    <p class="px-5 py-6 text-sm text-muted">Ödəniş qrafiki yoxdur.</p>
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
                                    <div class="text-xs text-muted">{{ $p->note ?? 'Mərhələ '.($i + 1) }} · {{ azdate($p->due_date) }}
                                        @if($late)<span class="text-danger font-medium"> · {{ (int) $p->due_date->diffInDays(today()) }} gün gecikir</span>@endif
                                        @if($p->paid_at)<span class="text-success"> · ödənilib {{ azdate($p->paid_at) }}</span>@endif
                                    </div>
                                </div>
                                @can('contracts.update')
                                    <form method="POST" action="{{ route('contracts.payments.toggle', [$contract, $p]) }}">@csrf
                                        <button class="btn btn-secondary btn-sm">{{ $p->paid_at ? 'Geri al' : 'Ödənildi' }}</button>
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
                        <h2 class="text-sm font-semibold">Bank əməliyyatları <span class="text-muted font-mono font-normal">{{ $transactions->count() }}</span></h2>
                        @can('bank.create')
                            <a href="{{ route('bank.transactions.create', ['contract_id' => $contract->id, 'counterparty_id' => $contract->counterparty_id, 'direction' => $contract->kind === 'sale' ? 'in' : 'out']) }}" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4"/> Əməliyyat</a>
                        @endcan
                    </header>
                    @if($transactions->isEmpty())
                        <p class="px-5 py-6 text-sm text-muted">Bu müqavilə üzrə əməliyyat yoxdur.</p>
                    @else
                        <div class="overflow-x-auto">
                        <table class="table-g table-stack">
                            <thead><tr><th>Tarix</th><th>Hesab</th><th>Təyinat</th><th class="!text-right">Məbləğ</th><th class="!text-right">AZN</th></tr></thead>
                            <tbody>
                            @foreach($transactions as $t)
                                <tr>
                                    <td data-label="Tarix" class="font-mono text-xs">{{ azdate($t->transaction_date) }}</td>
                                    <td data-label="Hesab" class="text-xs">{{ $t->account?->name }}</td>
                                    <td data-label="Təyinat"><a href="{{ route('bank.transactions.show', $t) }}" class="hover:text-brand-ink">{{ $t->purpose ?? '—' }}</a></td>
                                    <td data-label="Məbləğ" class="num {{ $t->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ $t->direction === 'in' ? '+' : '−' }}{{ money($t->amount, $t->currency) }}</td>
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
                    <header class="px-5 h-14 flex items-center border-b border-line"><h2 class="text-sm font-semibold">Əlavə razılaşmalar</h2></header>
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
                <div class="text-xs text-muted mb-2">{{ $contract->kind === 'sale' ? 'Müştəri' : 'Təchizatçı' }}</div>
                <a href="{{ route('counterparties.show', $contract->counterparty) }}" class="flex items-center gap-3 group">
                    <span class="grid place-items-center size-10 rounded-xl bg-brand-soft text-brand"><x-icon name="building" class="size-5"/></span>
                    <span class="min-w-0">
                        <span class="block font-semibold group-hover:text-brand-ink truncate">{{ $contract->counterparty->name }}</span>
                        <span class="block text-xs text-muted font-mono">{{ $contract->counterparty->voen ? 'VÖEN '.$contract->counterparty->voen : '' }}</span>
                    </span>
                </a>
                @if($contract->counterparty->trashed())<p class="mt-2 text-xs text-danger">Bu kontragent CRM-dən silinib.</p>@endif
            </section>
            @if($contract->notes)
                <section class="card p-5"><h2 class="text-sm font-semibold mb-2">Qeydlər</h2><p class="text-sm text-ink-2 whitespace-pre-line">{{ $contract->notes }}</p></section>
            @endif
            @include('partials.attachments', ['model' => $contract, 'type' => 'contract', 'ability' => 'contracts.update'])
            @can('contracts.delete')
                <x-delete-form :action="route('contracts.destroy', $contract)" label="Müqaviləni sil" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="'Müqavilə '.$contract->number.' silinəcək.'"/>
            @endcan
        </aside>
    </div>
</x-layouts.app>
