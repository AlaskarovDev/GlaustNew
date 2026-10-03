{{-- Buyer and supplier sides of a project, each with its contract; $project, $settled --}}
@php
    $margin = $project->contractMargin();
    $sides = [
        ['key' => 'sale', 'title' => 'Məhsulu alan tərəf', 'party' => $project->counterparty, 'contract' => $project->saleContract,
         'icon' => 'arrow-up-right', 'tone' => 'bg-brand-soft text-brand', 'bar' => 'bg-brand', 'kindLabel' => 'Satış müqaviləsi', 'flow' => 'Daxil olub'],
        ['key' => 'purchase', 'title' => 'Məhsulu satan tərəf', 'party' => $project->supplier, 'contract' => $project->purchaseContract,
         'icon' => 'arrow-down-left', 'tone' => 'bg-saffron-soft text-saffron', 'bar' => 'bg-saffron', 'kindLabel' => 'Alış müqaviləsi', 'flow' => 'Ödənilib'],
    ];
@endphp
<section aria-label="Müqavilələr">
    <div class="grid lg:grid-cols-2 gap-5">
        @foreach($sides as $s)
            @php $c = $s['contract']; @endphp
            <article class="card relative overflow-hidden flex flex-col">
                <div class="absolute inset-x-0 top-0 h-1 {{ $s['bar'] }}"></div>
                <header class="flex items-center gap-3 px-5 pt-5">
                    <span class="grid place-items-center size-10 rounded-xl {{ $s['tone'] }}"><x-icon :name="$s['icon']" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold">{{ $s['title'] }}</h2>
                        @if($s['party'])
                            <a href="{{ route('counterparties.show', $s['party']) }}" class="block text-sm text-brand-ink hover:underline truncate">{{ $s['party']->name }}</a>
                        @else
                            <p class="text-sm text-muted">Tərəf seçilməyib</p>
                        @endif
                    </div>
                </header>

                @if($c)
                    @php
                        $paid = $c->payments->whereNotNull('paid_at')->sum('amount');
                        $planned = $c->payments->sum('amount');
                        $pct = $c->amount_azn > 0 ? min(100, round($settled[$s['key']] / $c->amount_azn * 100)) : 0;
                        $over = round($settled[$s['key']] - (float) $c->amount_azn, 2);
                        $left = $c->daysLeft();
                    @endphp
                    <div class="px-5 pt-4 pb-5 flex-1">
                        <a href="{{ route('contracts.show', $c) }}" class="group flex items-start justify-between gap-3 rounded-xl border border-line p-4 hover:border-line-strong transition-colors">
                            <div class="min-w-0">
                                <div class="text-xs text-muted">{{ $s['kindLabel'] }}</div>
                                <div class="font-mono font-semibold group-hover:text-brand-ink">{{ $c->number }}</div>
                                <div class="text-sm text-ink-2 truncate">{{ $c->subject }}</div>
                            </div>
                            <x-status group="contract" :value="$c->status"/>
                        </a>
                        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                            <div><dt class="text-xs text-muted">Məbləğ</dt><dd class="font-mono font-semibold">{{ money($c->amount, $c->currency) }}</dd></div>
                            <div><dt class="text-xs text-muted">AZN ekvivalenti</dt><dd class="font-mono">{{ money($c->amount_azn) }}@if($c->currency !== 'AZN')<span class="block text-[11px] text-faint">CBAR {{ rate_fmt($c->cbar_rate) }} · {{ azdate($c->rate_date) }}</span>@endif</dd></div>
                            <div><dt class="text-xs text-muted">Tarix</dt><dd class="font-mono">{{ azdate($c->contract_date) }}</dd></div>
                            <div><dt class="text-xs text-muted">Bitmə</dt>
                                <dd class="font-mono">{{ azdate($c->end_date) }}
                                    @if($left !== null && in_array($c->status, ['signed', 'active']))
                                        <span @class(['block text-[11px] font-sans', 'text-danger' => $left <= 7, 'text-saffron' => $left > 7 && $left <= 30, 'text-faint' => $left > 30])>{{ $left < 0 ? abs($left).' gün keçib' : $left.' gün qalıb' }}</span>
                                    @endif
                                </dd></div>
                        </dl>
                        @can('bank.view')
                            <div class="mt-4">
                                <div class="flex justify-between text-xs mb-1.5"><span class="text-muted">{{ $s['flow'] }} (bank)</span><span class="font-mono">{{ money($settled[$s['key']]) }} · {{ $pct }}%</span></div>
                                <div class="h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full rounded-full {{ $over > 0.004 ? 'bg-danger' : $s['bar'] }}" style="width: {{ $pct }}%"></div></div>
                                @if($over > 0.004)
                                    <p class="mt-1.5 text-xs font-medium text-danger">Müqavilə məbləğindən {{ money($over) }} artıq {{ $s['key'] === 'sale' ? 'daxil olub' : 'ödənilib' }}</p>
                                @endif
                            </div>
                        @endcan
                        @if($planned > 0)
                            <p class="mt-3 text-xs text-muted">Ödəniş qrafiki: <span class="font-mono text-ink-2">{{ money($paid, $c->currency) }} / {{ money($planned, $c->currency) }}</span> ödənilib</p>
                        @endif
                    </div>
                @else
                    <div class="px-5 pt-4 pb-5 flex-1 flex flex-col">
                        <div class="flex-1 rounded-xl border-2 border-dashed border-line grid place-items-center text-center p-6">
                            <div>
                                <x-icon name="signature" class="size-6 text-faint mx-auto"/>
                                <p class="mt-2 text-sm text-muted">{{ $s['kindLabel'] }} bağlanmayıb</p>
                                <div class="mt-4 flex flex-wrap justify-center gap-2">
                                    @can('projects.update')
                                        <a href="{{ route('projects.edit', $project) }}#{{ $s['key'] }}" class="btn btn-secondary btn-sm"><x-icon name="link" class="size-4"/> Mövcudunu seç</a>
                                    @endcan
                                    @can('contracts.create')
                                        <a href="{{ route('contracts.create', array_filter(['project_id' => $project->id, 'kind' => $s['key'], 'counterparty_id' => $s['party']?->id])) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="size-4"/> Yeni müqavilə</a>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </article>
        @endforeach
    </div>

    @if($margin['margin'] !== null)
        <div class="mt-4 card px-5 py-4 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
            <span class="font-semibold">Müqavilələr üzrə marja</span>
            <span class="text-muted">Satış <span class="font-mono text-ink">{{ money($margin['sale']) }}</span></span>
            <span class="text-muted">− Alış <span class="font-mono text-ink">{{ money($margin['purchase']) }}</span></span>
            <span class="ml-auto font-mono text-lg font-semibold {{ $margin['margin'] >= 0 ? 'text-success' : 'text-danger' }}">
                {{ $margin['margin'] >= 0 ? '+' : '' }}{{ money($margin['margin']) }}
                @if($margin['percent'] !== null)<span class="text-sm">({{ num($margin['percent'], 1) }}%)</span>@endif
            </span>
        </div>
    @endif
</section>
