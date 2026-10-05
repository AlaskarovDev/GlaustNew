<x-layouts.app :title="__('Mənfəətin hesablanması')" wide>
    <x-page-header :title="__('Mənfəətin hesablanması')" icon="target"
                   :subtitle="__('Hər layihə və Trade üzrə mənfəət — proqnozdan akt tarixinə, kurs fərqlərindən yekuna qədər, avtomatik')"/>

    @include('profit._summary', ['t' => $totals])

    <div class="grid 2xl:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
        <section class="card overflow-hidden min-w-0">
            @if($projects->isEmpty())
                <x-empty icon="target" :title="__('Hələ hesablanacaq Trade yoxdur')" :text="__('Layihədə Trade yaradıb satıcının fakturasını import edəndən sonra mənfəət burada avtomatik hesablanır.')"/>
            @else
                <div class="overflow-x-auto">
                    <table class="table text-sm">
                        <thead><tr>
                            <th>{{ __('Layihə') }}</th><th class="!text-right">Trade</th><th class="!text-right">{{ __('Faktura') }}</th>
                            <th class="!text-right">{{ __('Proqnoz') }}</th><th class="!text-right">{{ __('Akt tarixinə') }}</th>
                            <th class="!text-right">{{ __('Xalis (CBAR)') }}</th><th class="!text-right">{{ __('Yekun') }}</th><th class="!text-right">{{ __('Cari nəticə') }}</th><th></th>
                        </tr></thead>
                        <tbody>
                            @foreach($projects as $p)
                                @php $t = $p['totals']; @endphp
                                <tr>
                                    <td data-label="{{ __('Layihə') }}"><a href="{{ route('profit.project', $p['project']) }}" class="font-medium hover:text-brand-ink">{{ $p['project']->name }}</a>
                                        <div class="text-[11px] text-muted font-mono">{{ $p['project']->code }}</div></td>
                                    <td data-label="Trade" class="num">{{ count($p['deals']) }}</td>
                                    <td data-label="{{ __('Faktura') }}" class="num">{{ $t['rows'] }}</td>
                                    <td data-label="{{ __('Proqnoz') }}" class="num">{{ $t['forecast']['count'] ? money($t['forecast']['profit']) : '—' }}</td>
                                    <td data-label="{{ __('Akt tarixinə') }}" class="num">{{ $t['act']['count'] ? money($t['act']['BP']) : '—' }}</td>
                                    <td data-label="{{ __('Xalis (CBAR)') }}" class="num">{{ $t['settle']['count'] ? money($t['settle']['BT']) : '—' }}</td>
                                    <td data-label="{{ __('Yekun') }}" class="num">{{ $t['bank']['count'] ? money($t['bank']['final']) : '—' }}</td>
                                    <td data-label="{{ __('Cari nəticə') }}" @class(['num font-semibold', 'text-success' => $t['best'] > 0, 'text-danger' => $t['best'] < 0])>{{ $t['rows'] ? money($t['best']) : '—' }}@if($t['estimated'])<span class="text-saffron" title="{{ __('təxmini') }}">*</span>@endif</td>
                                    <td><a href="{{ route('profit.project', $p['project']) }}" class="btn btn-ghost btn-sm btn-icon" aria-label="{{ __('Aç') }}"><x-icon name="chevron-right" class="size-4"/></a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="px-5 py-3 border-t border-line text-[11px] text-faint">{{ __('Cari nəticə — hər faktura üzrə çatılan ən son mərhələnin mənfəəti (yekun → xalis → akt tarixinə → proqnoz).') }}</p>
            @endif
        </section>
        @include('profit._method')
    </div>
</x-layouts.app>
