<x-layouts.app :title="'Yük '.$shipment->number">
    @php
        $modeIcons = ['road' => 'truck', 'rail' => 'train', 'sea' => 'ship', 'air' => 'plane'];
        $step = $shipment->stepIndex();
        $flow = \App\Models\Shipment::FLOW;
        $costTotal = $shipment->costs->sum('amount_azn');
    @endphp
    <x-page-header :title="'Yük '.$shipment->number" :subtitle="$shipment->origin.' → '.$shipment->destination" :back="route('shipments.index')">
        <x-slot:actions>
            @can('logistics.update')
                <a href="{{ route('shipments.edit', $shipment) }}" class="btn btn-primary"><x-icon name="pencil" class="size-4"/> Redaktə</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Stepper --}}
    <section class="card p-5 lg:p-6 mb-6">
        <div class="flex flex-wrap items-center gap-2 mb-5">
            <x-status group="shipment" :value="$shipment->status"/>
            <span class="badge badge-slate"><x-icon :name="$modeIcons[$shipment->transport_mode] ?? 'truck'" class="size-3.5"/> {{ config('glaust.transport_modes.'.$shipment->transport_mode) }}</span>
            <span class="badge badge-slate">{{ config('glaust.shipment_directions.'.$shipment->direction) }}</span>
            @if($shipment->isDelayed())<span class="badge badge-rose">{{ (int) $shipment->eta->diffInDays(today()) }} gün gecikir</span>@endif
        </div>
        <ol class="grid grid-cols-6 gap-1 sm:gap-2" aria-label="Status zənciri">
            @foreach($flow as $i => $s)
                <li class="relative">
                    <div @class(['h-1.5 rounded-full transition-colors duration-500', 'bg-brand' => $i <= $step, 'bg-surface-2' => $i > $step])></div>
                    <div class="mt-2 flex items-center gap-1.5">
                        <span @class(['grid place-items-center size-5 rounded-full text-[10px] font-semibold shrink-0', 'bg-brand text-white' => $i < $step, 'bg-brand text-white ring-4 ring-brand/20' => $i === $step, 'bg-surface-2 text-faint' => $i > $step])>
                            @if($i < $step)<x-icon name="check" class="size-3" :stroke="3"/>@else{{ $i + 1 }}@endif
                        </span>
                        <span @class(['hidden sm:block text-xs truncate', 'text-ink font-medium' => $i === $step, 'text-muted' => $i !== $step])>{{ status_label('shipment', $s) }}</span>
                    </div>
                </li>
            @endforeach
        </ol>
        @can('logistics.update')
            @if($shipment->status !== 'delivered')
                <form method="POST" action="{{ route('shipments.status', $shipment) }}" class="mt-5 flex flex-col sm:flex-row gap-2">
                    @csrf
                    <input type="hidden" name="status" value="{{ $flow[min($step + 1, count($flow) - 1)] }}">
                    <input name="note" class="input flex-1" placeholder="Qeyd (istəyə bağlı): məs. sərhəddən keçdi" aria-label="Qeyd">
                    <button class="btn btn-primary shrink-0"><x-icon name="arrow-right" class="size-4"/> «{{ status_label('shipment', $flow[min($step + 1, count($flow) - 1)]) }}» et</button>
                </form>
            @endif
        @endcan
    </section>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card overflow-hidden">
                <header class="flex items-center justify-between px-5 h-14 border-b border-line">
                    <h2 class="text-sm font-semibold">Logistika xərcləri</h2>
                    <span class="font-mono text-sm font-semibold">{{ money($costTotal) }}</span>
                </header>
                @if($shipment->costs->isEmpty())
                    <p class="px-5 py-5 text-sm text-muted">Xərc qeyd edilməyib.</p>
                @else
                    <div class="overflow-x-auto">
                    <table class="table-g table-stack">
                        <thead><tr><th>Tarix</th><th>Növ</th><th>Kontragent</th><th class="!text-right">Məbləğ</th><th class="!text-right">AZN</th><th></th></tr></thead>
                        <tbody>
                        @foreach($shipment->costs as $c)
                            <tr>
                                <td data-label="Tarix" class="font-mono text-xs">{{ azdate($c->cost_date) }}</td>
                                <td data-label="Növ">{{ config('glaust.cost_types.'.$c->cost_type) }}@if($c->note)<div class="text-xs text-muted">{{ $c->note }}</div>@endif</td>
                                <td data-label="Kontragent" class="text-xs">{{ $c->counterparty?->name ?? '—' }}</td>
                                <td data-label="Məbləğ" class="num">{{ money($c->amount, $c->currency) }}</td>
                                <td data-label="AZN" class="num">{{ money($c->amount_azn) }}@if($c->currency !== 'AZN')<div class="text-[11px] text-faint">{{ rate_fmt($c->cbar_rate) }}</div>@endif</td>
                                <td data-label="" class="text-right">@can('logistics.update')<x-delete-form :action="route('shipments.costs.destroy', [$shipment, $c])" label="" message="Xərc silinsin?"/>@endcan</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
                @can('logistics.update')
                    <form method="POST" action="{{ route('shipments.costs.store', $shipment) }}" class="p-4 border-t border-line bg-surface-2/60 grid sm:grid-cols-6 gap-2">
                        @csrf
                        <select name="cost_type" class="input sm:col-span-2" aria-label="Xərc növü">@foreach(config('glaust.cost_types') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                        <input type="date" name="cost_date" value="{{ today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" class="input font-mono sm:col-span-2" aria-label="Tarix" required>
                        <input name="amount" class="input font-mono text-right" placeholder="Məbləğ" inputmode="decimal" aria-label="Məbləğ" required>
                        <select name="currency" class="input" aria-label="Valyuta">@foreach(config('glaust.currencies') as $cur)<option>{{ $cur }}</option>@endforeach</select>
                        <input name="note" class="input sm:col-span-4" placeholder="Qeyd" aria-label="Qeyd">
                        <button class="btn btn-secondary sm:col-span-2"><x-icon name="plus" class="size-4"/> Xərc əlavə et</button>
                        @if($errors->hasAny(['amount', 'currency', 'cost_date']))<p class="field-error sm:col-span-6">{{ $errors->first('currency') ?: $errors->first('amount') ?: $errors->first('cost_date') }}</p>@endif
                    </form>
                @endcan
            </section>

            <section class="card p-6">
                <h2 class="text-sm font-semibold mb-4">Status tarixçəsi</h2>
                <ol class="relative border-l-2 border-line ml-2 space-y-5">
                    @foreach($shipment->history as $h)
                        <li class="ml-5">
                            <span class="absolute -left-[7px] mt-1 size-3 rounded-full bg-brand ring-4 ring-surface"></span>
                            <div class="flex flex-wrap items-center gap-2"><x-status group="shipment" :value="$h->status" :dot="false"/><span class="text-xs text-muted font-mono">{{ azdate($h->created_at, true) }}</span></div>
                            <div class="text-xs text-muted mt-1">{{ $h->user?->name ?? 'Sistem' }}{{ $h->note ? ' · '.$h->note : '' }}</div>
                        </li>
                    @endforeach
                </ol>
            </section>
            @include('partials.history', ['history' => $history])
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-5 text-sm space-y-3">
                @foreach([
                    ['Daşıyıcı', $shipment->carrier ? '<a class="text-brand-ink hover:underline" href="'.route('counterparties.show', $shipment->carrier).'">'.e($shipment->carrier->name).'</a>' : '—'],
                    ['Nəqliyyat vasitəsi', e($shipment->vehicle ?? '—')],
                    ['Konteyner', '<span class="font-mono">'.e($shipment->container_no ?? '—').'</span>'],
                    ['CMR / konosament', '<span class="font-mono">'.e($shipment->document_no ?? '—').'</span>'],
                    ['Yük', e($shipment->cargo_description ?? '—')],
                    ['Çəki / həcm', ($shipment->weight_kg ? num($shipment->weight_kg).' kq' : '—').' / '.($shipment->volume_m3 ? num($shipment->volume_m3, 3).' m³' : '—')],
                    ['Yüklənmə', '<span class="font-mono">'.azdate($shipment->loading_date).'</span>'],
                    ['Gözlənilən çatma', '<span class="font-mono '.($shipment->isDelayed() ? 'text-danger' : '').'">'.azdate($shipment->eta).'</span>'],
                    ['Təhvil', '<span class="font-mono">'.azdate($shipment->delivered_at, true).'</span>'],
                    ['Layihə', $shipment->project ? '<a class="text-brand-ink hover:underline" href="'.route('projects.show', $shipment->project).'">'.e($shipment->project->code).'</a>' : '—'],
                    ['Müqavilə', $shipment->contract ? '<a class="text-brand-ink hover:underline font-mono" href="'.route('contracts.show', $shipment->contract).'">'.e($shipment->contract->number).'</a>' : '—'],
                    ['Məsul', e($shipment->responsible?->name ?? '—')],
                ] as [$k, $v])
                    <div class="flex justify-between gap-4"><span class="text-muted shrink-0">{{ $k }}</span><span class="text-right min-w-0 break-words">{!! $v !!}</span></div>
                @endforeach
            </section>
            @if($shipment->notes)<section class="card p-5"><h2 class="text-sm font-semibold mb-2">Qeydlər</h2><p class="text-sm whitespace-pre-line">{{ $shipment->notes }}</p></section>@endif
            @include('partials.attachments', ['model' => $shipment, 'type' => 'shipment', 'ability' => 'logistics.update'])
            @can('logistics.delete')
                <x-delete-form :action="route('shipments.destroy', $shipment)" label="Yükü sil" button="btn btn-ghost w-full text-danger hover:!bg-danger-soft" :message="'Yük '.$shipment->number.' silinəcək.'"/>
            @endcan
        </aside>
    </div>
</x-layouts.app>
