<x-layouts.app :title="__('Mənfəət').' · '.$project->code" wide>
    <x-page-header :title="$project->name" icon="target"
                   :subtitle="__('Mənfəətin hesablanması').' · '.$project->code" :back="route('profit.index')">
        <x-slot:actions>
            <a href="{{ route('projects.show', [$project, 'tab' => 'deals']) }}" class="btn btn-secondary"><x-icon name="folder" class="size-4"/> {{ __('Layihəyə keç') }}</a>
            @can('reports.export')
                <a href="{{ route('profit.export', $project) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4"/> Excel</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('profit._hero', ['t' => $totals, 'meta' => count($deals).' Trade · '.$totals['rows'].' '.__('satıcı fakturası')])

    <div class="space-y-5">
        @forelse($deals as $d)
            @php $dt = $d['totals']; $deal = $d['deal']; @endphp
            <section class="card overflow-hidden">
                <header class="flex flex-wrap items-center gap-x-5 gap-y-3 px-5 py-4 border-b border-line">
                    <span class="grid place-items-center size-10 rounded-xl bg-surface-2 text-ink-2 shrink-0"><x-icon name="transfer" class="size-5"/></span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('profit.deal', $deal) }}" class="font-semibold hover:text-brand-ink">Trade {{ $deal->code }} <span class="font-normal text-ink-2">· {{ $deal->title }}</span></a>
                        <div class="text-xs text-muted truncate">@if($deal->supplier || $deal->counterparty){{ $deal->supplier?->name ?? '—' }} <span class="text-faint">→</span> {{ $deal->counterparty?->name ?? '—' }} · @endif{{ azdate($deal->deal_date) }}</div>
                    </div>
                    <div class="w-40 hidden sm:block">@include('profit._bar', ['t' => $dt, 'r' => null])</div>
                    <div class="text-right">
                        <div class="text-[11px] uppercase tracking-wider text-muted">{{ __('Cari nəticə') }}</div>
                        <div @class(['font-mono text-lg font-semibold', 'pf-pos' => $dt['best'] > 0, 'pf-neg' => $dt['best'] < 0, 'text-faint' => ! $dt['reached']])>{{ $dt['reached'] ? money($dt['best']) : '—' }}</div>
                    </div>
                    <a href="{{ route('profit.deal', $deal) }}" class="btn btn-secondary btn-sm">{{ __('Addım-addım') }} <x-icon name="chevron-right" class="size-4"/></a>
                </header>
                @if($d['rows'])
                    <div class="overflow-x-auto">
                        <table class="table-g table-stack md:table-fixed">
                            <colgroup><col class="md:w-[20%]"><col class="md:w-[11%]"><col class="md:w-[12%]"><col class="md:w-[13%]"><col><col><col><col></colgroup>
                            <thead><tr>
                                <th>{{ __('Satıcı fakturası') }}</th>
                                <th class="!text-right">D · {{ __('satıcıya') }}</th>
                                <th class="!text-right">H · {{ __('alıcıdan') }}</th>
                                <th class="w-32">{{ __('Mərhələ') }}</th>
                                <th class="!text-right">{{ __('Proqnoz') }}</th>
                                <th class="!text-right">{{ __('Akt tarixinə') }}</th>
                                <th class="!text-right">{{ __('Xalis (CBAR)') }}</th>
                                <th class="!text-right">{{ __('Yekun') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach($d['rows'] as $r)
                                @php $cell = fn ($v) => $v === null ? '<span class="text-faint">—</span>' : '<span class="'.($v > 0 ? 'pf-pos' : ($v < 0 ? 'pf-neg' : '')).'">'.e(money($v)).'</span>'; @endphp
                                <tr class="cursor-pointer" @click="if (!$event.target.closest('a')) window.location = @js(route('profit.deal', $deal).'#invoice-'.$r['invoice']->id)">
                                    <td data-label="{{ __('Satıcı fakturası') }}">
                                        <a href="{{ route('profit.deal', $deal) }}#invoice-{{ $r['invoice']->id }}" class="font-mono font-medium text-ink hover:text-brand-ink">{{ $r['invoice']->number }}</a>
                                        <div class="text-[11px] text-muted">{{ azdate($r['invoice']->invoice_date) }}@if($r['sale']) · {{ $r['sale']->number }}@endif</div>
                                    </td>
                                    <td data-label="D" class="num">{{ money($r['D'], $r['cur']) }}</td>
                                    <td data-label="H" class="num">{{ $r['H'] !== null ? money($r['H'], $r['saleCur']) : '—' }}</td>
                                    <td data-label="{{ __('Mərhələ') }}">@include('profit._bar', ['r' => $r])<div class="mt-1">@include('profit._stage', ['stage' => $r['stage']])</div></td>
                                    <td data-label="{{ __('Proqnoz') }}" class="num">{!! $cell($r['forecast']['profit'] ?? null) !!}</td>
                                    <td data-label="{{ __('Akt tarixinə') }}" class="num">{!! $cell($r['act']['BP'] ?? null) !!}</td>
                                    <td data-label="{{ __('Xalis (CBAR)') }}" class="num">{!! $cell($r['settle']['BT'] ?? null) !!}</td>
                                    <td data-label="{{ __('Yekun') }}" class="num font-semibold">{!! $cell($r['bank']['final'] ?? null) !!}@if($r['estimated'])<span class="text-saffron" title="{{ __('təxmini') }}">*</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                            @if(count($d['rows']) > 1)
                                <tfoot class="hidden md:table-footer-group"><tr class="bg-surface-2 font-semibold text-sm">
                                    <td class="px-4 py-3" colspan="4">{{ __('Cəmi') }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ $dt['forecast']['count'] ? money($dt['forecast']['profit']) : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ $dt['act']['count'] ? money($dt['act']['BP']) : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ $dt['settle']['count'] ? money($dt['settle']['BT']) : '—' }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ $dt['bank']['count'] ? money($dt['bank']['final']) : '—' }}</td>
                                </tr></tfoot>
                            @endif
                        </table>
                    </div>
                @else
                    <p class="px-5 py-5 text-sm text-muted">{{ __('Bu Trade-də hələ satıcı fakturası yoxdur.') }}</p>
                @endif
            </section>
        @empty
            <div class="card"><x-empty icon="folder" :title="__('Layihədə Trade yoxdur')" :text="__('Trade əlavə edib satıcı fakturasını import edin.')"/></div>
        @endforelse
    </div>

    @include('profit._method')
</x-layouts.app>
