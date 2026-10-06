<x-layouts.app :title="__('Mənfəətin hesablanması')" wide>
    <x-page-header :title="__('Mənfəətin hesablanması')" icon="target"
                   :subtitle="__('Hər layihə və Trade üzrə mənfəət — proqnozdan akt tarixinə, kurs fərqlərindən yekuna qədər, avtomatik')"/>

    @php $tradeCount = $projects->sum(fn ($p) => count($p['deals'])); @endphp
    @include('profit._hero', ['t' => $totals, 'meta' => $projects->count().' '.__('layihə').' · '.$tradeCount.' Trade · '.$totals['rows'].' '.__('satıcı fakturası')])

    @if($projects->isEmpty())
        <div class="card"><x-empty icon="target" :title="__('Hələ hesablanacaq Trade yoxdur')" :text="__('Layihədə Trade yaradıb satıcının fakturasını import edəndən sonra mənfəət burada avtomatik hesablanır.')"/></div>
    @else
        <h2 class="text-sm font-semibold text-ink-2 mb-3">{{ __('Layihələr') }} <span class="font-mono text-muted font-normal">{{ $projects->count() }}</span></h2>
        <div class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-4 stagger">
            @foreach($projects as $i => $p)
                @php $t = $p['totals']; $tone = $t['best'] > 0 ? 'pf-pos' : ($t['best'] < 0 ? 'pf-neg' : ''); @endphp
                <a href="{{ route('profit.project', $p['project']) }}" class="card card-hover p-5 block min-w-0" style="--i:{{ $i }}">
                    <div class="flex items-start gap-3">
                        <span class="grid place-items-center size-10 rounded-xl bg-brand-soft text-brand-ink shrink-0"><x-icon name="folder" class="size-5"/></span>
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold truncate">{{ $p['project']->name }}</div>
                            <div class="text-xs text-muted"><span class="font-mono">{{ $p['project']->code }}</span> · {{ count($p['deals']) }} Trade · {{ $t['rows'] }} {{ __('faktura') }}</div>
                        </div>
                        <x-icon name="chevron-right" class="size-5 text-faint shrink-0"/>
                    </div>

                    <div class="mt-4 flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-muted">{{ __('Cari nəticə') }}</div>
                            <div @class(['text-2xl font-semibold font-mono tabular-nums', $tone => $t['reached'], 'text-faint' => ! $t['reached']])>{{ $t['reached'] ? money($t['best']) : '—' }}@if($t['estimated'])<span class="text-saffron text-base align-top" title="{{ __('təxmini') }}">*</span>@endif</div>
                            @unless($t['reached'])<div class="text-xs text-muted">{{ __('Hesablama başlamayıb — proforma (RUB konvertasiyası) gözlənilir') }}</div>@endunless
                        </div>
                    </div>
                    @include('profit._bar', ['t' => $t, 'r' => null, 'class' => 'mt-3'])

                    <dl class="pf-rows mt-4">
                        @foreach([[__('Proqnoz'), 'forecast', 'profit'], [__('Akt tarixinə'), 'act', 'BP'], [__('Xalis (CBAR)'), 'settle', 'BT'], [__('Yekun'), 'bank', 'final']] as [$label, $stage, $key])
                            @php $v = $t[$stage]['count'] ? $t[$stage][$key] : null; @endphp
                            <div><dt>{{ $label }} <span class="text-faint text-[11px]">{{ $t[$stage]['count'] }}/{{ $t['rows'] }}</span></dt>
                                <dd @class(['pf-pos' => $v > 0, 'pf-neg' => $v < 0, 'text-faint' => $v === null])>{{ $v === null ? '—' : money($v) }}</dd></div>
                        @endforeach
                    </dl>
                </a>
            @endforeach
        </div>
    @endif

    @include('profit._method')
</x-layouts.app>
