<x-layouts.app :title="__('Bank əməliyyatı')">
    @php
        $in = $tx->direction === 'in';
        $title = $tx->kind === 'regular' ? ($in ? 'Mədaxil' : 'Məxaric') : config('glaust.transaction_kinds.'.$tx->kind);
    @endphp
    <x-page-header :title="$title.' · '.azdate($tx->transaction_date)" :back="route('bank.transactions.index')">
        <x-slot:actions>
            @can('bank.update')
                @unless($tx->transfer_group)
                    <a href="{{ route('bank.transactions.edit', $tx) }}" class="btn btn-primary"><x-icon name="pencil" class="size-4"/> {{ __('Redaktə') }}</a>
                @endunless
            @endcan
            @can('bank.delete')
                <x-delete-form :action="route('bank.transactions.destroy', $tx)" :button="__('btn btn-secondary text-danger')" :message="$tx->transfer_group ? 'Köçürmənin hər iki tərəfi silinəcək.' : 'Əməliyyat silinəcək.'"/>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <section class="card p-6">
                <div class="flex flex-wrap items-center gap-4">
                    <span @class(['grid place-items-center size-14 rounded-2xl', 'bg-success-soft text-success' => $in, 'bg-danger-soft text-danger' => ! $in])>
                        <x-icon :name="$tx->kind !== 'regular' ? 'transfer' : ($in ? 'arrow-down-left' : 'arrow-up-right')" class="size-7"/>
                    </span>
                    <div>
                        <div @class(['text-3xl font-semibold font-mono tabular', 'text-success' => $in, 'text-danger' => ! $in])>{{ $in ? '+' : '−' }}{{ money($tx->amount, $tx->currency) }}</div>
                        <div class="text-sm text-muted">{{ $tx->account->name }} · {{ $tx->account->bank_name }}</div>
                    </div>
                </div>
                <dl class="mt-6 pt-6 border-t border-line grid sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('CBAR məzənnəsi') }}</dt><dd class="font-mono">{{ rate_fmt($tx->cbar_rate) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Tətbiq olunan') }}</dt><dd class="font-mono">{{ rate_fmt($tx->applied_rate) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('AZN (tətbiq olunan)') }}</dt><dd class="font-mono font-semibold">{{ money($tx->amount_azn) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">AZN (CBAR)</dt><dd class="font-mono">{{ money($tx->cbar_amount_azn) }}</dd></div>
                    @if($tx->kind === 'regular' && $tx->currency !== 'AZN')
                        @php $diff = $tx->exchangeDifference(); @endphp
                        <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-muted">{{ __('Kurs fərqi') }}</dt><dd @class(['font-mono font-semibold', 'text-success' => $diff > 0, 'text-danger' => $diff < 0])>{{ $diff > 0 ? '+' : '' }}{{ money($diff) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Təyinat') }}</dt><dd class="text-right">{{ $tx->purpose ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Sənəd №') }}</dt><dd class="font-mono">{{ $tx->reference ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Kateqoriya') }}</dt><dd>{{ $tx->category?->name ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('Daxil edən') }}</dt><dd>{{ $tx->creator?->name ?? '—' }}</dd></div>
                </dl>
            </section>

            @if($counterpart)
                @php $result = $tx->kind === 'conversion' ? round(($in ? $tx->cbar_amount_azn - $counterpart->cbar_amount_azn : $counterpart->cbar_amount_azn - $tx->cbar_amount_azn), 2) : null; @endphp
                <section class="card p-6">
                    <h2 class="text-sm font-semibold mb-4">{{ $tx->kind === 'conversion' ? 'Konvertasiyanın digər tərəfi' : 'Köçürmənin digər tərəfi' }}</h2>
                    <a href="{{ route('bank.transactions.show', $counterpart) }}" class="flex items-center gap-4 p-4 rounded-xl border border-line hover:border-line-strong transition-colors">
                        <x-icon name="transfer" class="size-5 text-brand"/>
                        <div class="flex-1 min-w-0"><div class="text-sm font-medium">{{ $counterpart->account->name }} ({{ $counterpart->currency }})</div><div class="text-xs text-muted">{{ $counterpart->direction === 'in' ? 'daxil olub' : 'silinib' }}</div></div>
                        <div class="font-mono font-semibold {{ $counterpart->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ $counterpart->direction === 'in' ? '+' : '−' }}{{ money($counterpart->amount, $counterpart->currency) }}</div>
                    </a>
                    @if($result !== null)
                        <p class="mt-3 text-sm">{{ __('Konvertasiyanın nəticəsi (CBAR ilə müqayisədə):') }}
                            <span @class(['font-mono font-semibold', 'text-success' => $result > 0, 'text-danger' => $result < 0])>{{ $result > 0 ? '+' : '' }}{{ money($result) }}</span></p>
                    @endif
                </section>
            @endif

            @include('partials.history', ['history' => $history])
        </div>

        <aside class="space-y-6 lg:sticky lg:top-24">
            <section class="card p-5 text-sm space-y-3">
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Kontragent') }}</span>@if($tx->counterparty)<a href="{{ route('counterparties.show', $tx->counterparty) }}" class="text-brand-ink hover:underline text-right">{{ $tx->counterparty->name }}</a>@else<span>—</span>@endif</div>
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Müqavilə') }}</span>@if($tx->contract)<a href="{{ route('contracts.show', $tx->contract) }}" class="text-brand-ink hover:underline font-mono">{{ $tx->contract->number }}</a>@else<span>—</span>@endif</div>
                <div class="flex justify-between gap-3"><span class="text-muted">{{ __('Layihə') }}</span>@if($tx->project)<a href="{{ route('projects.show', $tx->project) }}" class="text-brand-ink hover:underline">{{ $tx->project->code }}</a>@else<span>—</span>@endif</div>
            </section>
            @include('partials.attachments', ['model' => $tx, 'type' => 'bank_transaction', 'ability' => 'bank.update'])
        </aside>
    </div>
</x-layouts.app>
