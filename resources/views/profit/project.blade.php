<x-layouts.app :title="__('Mənfəət').' · '.$project->code" wide>
    <x-page-header :title="__('Mənfəətin hesablanması').' · '.$project->name" icon="target"
                   :subtitle="$project->code.' · '.count($deals).' Trade · '.$totals['rows'].' '.__('satıcı fakturası')" :back="route('profit.index')">
        <x-slot:actions>
            <a href="{{ route('projects.show', [$project, 'tab' => 'deals']) }}" class="btn btn-secondary"><x-icon name="folder" class="size-4"/> {{ __('Layihəyə keç') }}</a>
            @can('reports.export')
                <a href="{{ route('profit.export', $project) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4"/> Excel</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('profit._summary', ['t' => $totals])

    <div class="grid 2xl:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            @forelse($deals as $d)
                @php $dt = $d['totals']; @endphp
                <section class="card overflow-hidden">
                    <header class="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-line">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('profit.deal', $d['deal']) }}" class="font-semibold hover:text-brand-ink">Trade {{ $d['deal']->code }} · {{ $d['deal']->title }}</a>
                            <div class="text-xs text-muted">{{ $d['deal']->supplier?->name ?? '—' }} → {{ $d['deal']->counterparty?->name ?? '—' }} · {{ azdate($d['deal']->deal_date) }}</div>
                        </div>
                        <div class="text-right"><div class="text-xs text-muted">{{ __('Cari nəticə') }}</div>
                            <div @class(['font-mono font-semibold', 'text-success' => $dt['best'] > 0, 'text-danger' => $dt['best'] < 0])>{{ $dt['rows'] ? money($dt['best']) : '—' }}</div></div>
                        <a href="{{ route('profit.deal', $d['deal']) }}" class="btn btn-ghost btn-sm">{{ __('Addım-addım') }} <x-icon name="chevron-right" class="size-4"/></a>
                    </header>
                    @if($d['rows'])
                        <div class="overflow-x-auto">
                            <table class="table text-sm">
                                <thead><tr>
                                    <th>{{ __('Satıcı fakturası') }}</th>
                                    <th class="!text-right">D</th><th class="!text-right">H</th>
                                    <th class="!text-right">{{ __('Proqnoz') }}</th>
                                    <th class="!text-right" title="BN − BO">{{ __('Akt tarixinə') }}</th>
                                    <th class="!text-right" title="BQ + BR − BS">{{ __('Kurs fərqləri') }}</th>
                                    <th class="!text-right" title="AJ + BB">{{ __('Komissiyalar') }}</th>
                                    <th class="!text-right" title="BT">{{ __('Xalis (CBAR)') }}</th>
                                    <th class="!text-right">{{ __('Bank, xərclər') }}</th>
                                    <th class="!text-right" title="BE">{{ __('Yekun') }}</th>
                                    <th>{{ __('Mərhələ') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach($d['rows'] as $r)
                                        @php $fx = $r['settle'] && isset($r['settle']['BQ']) ? $r['settle']['BQ'] + $r['settle']['BR'] - $r['settle']['BS'] : null; @endphp
                                        <tr>
                                            <td data-label="{{ __('Satıcı fakturası') }}"><a href="{{ route('profit.deal', $d['deal']) }}#invoice-{{ $r['invoice']->id }}" class="font-medium hover:text-brand-ink">{{ $r['invoice']->number }}</a>
                                                <div class="text-[11px] text-muted">{{ azdate($r['invoice']->invoice_date) }}</div></td>
                                            <td data-label="D" class="num">{{ money($r['D'], $r['cur']) }}</td>
                                            <td data-label="H" class="num">{{ $r['H'] !== null ? money($r['H'], $r['saleCur']) : '—' }}</td>
                                            <td data-label="{{ __('Proqnoz') }}" class="num">{{ $r['forecast'] ? money($r['forecast']['profit']) : '—' }}</td>
                                            <td data-label="{{ __('Akt tarixinə') }}" class="num">{{ $r['act'] ? money($r['act']['BP']) : '—' }}</td>
                                            <td data-label="{{ __('Kurs fərqləri') }}" @class(['num', 'text-danger' => $fx < 0, 'text-success' => $fx > 0])>{{ $fx === null ? '—' : money($fx) }}</td>
                                            <td data-label="{{ __('Komissiyalar') }}" class="num">{{ $r['settle'] ? '−'.money($r['settle']['AJ'] + $r['settle']['BB']) : '—' }}</td>
                                            <td data-label="{{ __('Xalis (CBAR)') }}" class="num font-medium">{{ $r['settle'] ? money($r['settle']['BT']) : '—' }}</td>
                                            <td data-label="{{ __('Bank, xərclər') }}" class="num">{{ $r['bank'] ? money($r['bank']['total'] - $r['bank']['expenses']) : '—' }}</td>
                                            <td data-label="{{ __('Yekun') }}" @class(['num font-semibold', 'text-success' => ($r['bank']['final'] ?? 0) > 0, 'text-danger' => ($r['bank']['final'] ?? 0) < 0])>{{ $r['bank'] ? money($r['bank']['final']) : '—' }}</td>
                                            <td data-label="{{ __('Mərhələ') }}">@include('profit._stage', ['stage' => $r['stage']])</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if(count($d['rows']) > 1)
                                    <tfoot><tr class="font-semibold">
                                        <td>{{ __('Cəmi') }}</td><td></td><td></td>
                                        <td class="num">{{ $dt['forecast']['count'] ? money($dt['forecast']['profit']) : '—' }}</td>
                                        <td class="num">{{ $dt['act']['count'] ? money($dt['act']['BP']) : '—' }}</td>
                                        <td class="num">{{ $dt['act']['count'] && $dt['settle']['count'] ? money($dt['settle']['BQ'] + $dt['settle']['BR'] - $dt['settle']['BS']) : '—' }}</td>
                                        <td class="num">{{ $dt['settle']['count'] ? '−'.money($dt['settle']['AJ'] + $dt['settle']['BB']) : '—' }}</td>
                                        <td class="num">{{ $dt['settle']['count'] ? money($dt['settle']['BT']) : '—' }}</td>
                                        <td class="num">{{ $dt['bank']['count'] ? money($dt['bank']['total'] - $dt['bank']['expenses']) : '—' }}</td>
                                        <td class="num">{{ $dt['bank']['count'] ? money($dt['bank']['final']) : '—' }}</td>
                                        <td></td>
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
    </div>
</x-layouts.app>
