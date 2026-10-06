<x-layouts.app :title="__('Mənfəət').' · '.$deal->code">
    <x-page-header :title="__('Mənfəətin hesablanması').' · Trade '.$deal->code" icon="target"
                   :subtitle="$deal->title.($deal->supplier || $deal->counterparty ? ' · '.($deal->supplier?->name ?? '—').' → '.($deal->counterparty?->name ?? '—') : '')"
                   :back="route('profit.project', $deal->project)">
        <x-slot:actions>
            <a href="{{ route('deals.show', $deal) }}" class="btn btn-secondary"><x-icon name="arrow-right" class="size-4"/> {{ __('Trade-ə keç') }}</a>
        </x-slot:actions>
    </x-page-header>

    @include('profit._hero', ['t' => $totals, 'meta' => 'Trade '.$deal->code.' · '.$totals['rows'].' '.__('satıcı fakturası')])

    @forelse($rows as $r)
        <article class="card mb-6 overflow-hidden" id="invoice-{{ $r['invoice']->id }}">
            <header class="flex flex-wrap items-center gap-x-6 gap-y-2 px-5 py-4 border-b border-line">
                <div class="min-w-0">
                    <div class="text-xs text-muted">{{ __('Satıcı fakturası') }} · {{ azdate($r['invoice']->invoice_date) }}</div>
                    <a href="{{ route('invoices.show', $r['invoice']) }}" class="font-semibold hover:text-brand-ink">{{ $r['invoice']->number }}</a>
                </div>
                <div><div class="text-xs text-muted">D · {{ __('Satıcıya') }}</div><div class="font-mono font-medium">{{ money($r['D'], $r['cur']) }}</div></div>
                <div><div class="text-xs text-muted">H · {{ __('Alıcıdan') }} @if($r['sale'])({{ $r['sale']->number }})@endif</div><div class="font-mono font-medium">{{ $r['H'] !== null ? money($r['H'], $r['saleCur']) : '—' }}</div></div>
                <div class="ml-auto flex items-center gap-4">
                    <div class="w-32 hidden sm:block">@include('profit._bar', ['r' => $r])<div class="mt-1 text-right">@include('profit._stage', ['stage' => $r['stage']])</div></div>
                    <div class="text-right"><div class="text-xs text-muted">{{ __('Cari nəticə') }}</div>
                        <div @class(['font-mono text-lg font-semibold', 'pf-pos' => ($r['best'] ?? 0) > 0, 'pf-neg' => ($r['best'] ?? 0) < 0])>{{ $r['best'] === null ? '—' : money($r['best']) }}</div></div>
                </div>
            </header>
            <div class="p-5">@include('profit._steps', ['r' => $r])</div>
        </article>
    @empty
        <div class="card"><x-empty icon="receipt" :title="__('Satıcı fakturası yoxdur')" :text="__('Trade-ə satıcının fakturası import olunanda hesablama burada görünəcək.')"/></div>
    @endforelse
    @include('profit._method')
</x-layouts.app>
