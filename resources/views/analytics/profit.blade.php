<x-layouts.app :title="__('Mənfəət və zərər')" wide>
    <x-page-header :title="__('Mənfəət və zərər')" :icon="$icon" :subtitle="__('Şirkətin MZ cədvəli kimi: gəlirlər və xərclər hesab nömrələri üzrə, aylar və dövr üzrə, AZN')">
        <x-slot:actions>
            <a href="{{ route('analytics.show', ['report' => 'profit', 'from' => $from, 'to' => $to, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
        </x-slot:actions>
    </x-page-header>

    <nav class="flex gap-2 overflow-x-auto pb-1 mb-6" aria-label="{{ __('Hesabatlar') }}">
        @foreach($all as $key => [$navLabel, $ico])
            <a href="{{ route('analytics.show', $key) }}" @class(['badge !h-9 !px-3.5 !text-[13px] gap-1.5 shrink-0', 'badge-teal' => $key === $report, 'badge-slate hover:!text-ink' => $key !== $report]) @if($key === $report) aria-current="page" @endif>
                <x-icon :name="$ico" class="size-4"/> {{ __($navLabel) }}
            </a>
        @endforeach
    </nav>

    @php
        $months = $data['months'];
        $lines = $data['lines'];
        $n = fn ($v) => abs($v) < 0.005 ? '—' : ($v < 0 ? '−' : '').num(abs($v));
        $sum = fn (array $v) => round(array_sum($v), 2);
        $period = $label($from).' — '.$label($to);
        $now = today();
        $quick = [
            __('Bu il') => [$now->format('Y').'-01', $now->format('Y-m')],
            __('Keçən il') => [($now->year - 1).'-01', ($now->year - 1).'-12'],
        ];
        // the explanation behind each figure
        $why = [];
        foreach ($lines as $code => $l) {
            foreach ($months as $m) {
                $why["{$code}|{$m}"] = ['h' => $code.' · '.$l['label'], 's' => $label($m), 'rows' => collect($l['items'][$m] ?? [])->sortBy('s')->values()->all(), 'v' => $l['values'][$m]];
            }
            $why["{$code}|all"] = ['h' => $code.' · '.$l['label'], 's' => $period, 'rows' => collect(array_merge([], ...array_values($l['items'])))->sortBy('s')->values()->all(), 'v' => $l['total']];
        }
        $tip = fn (string $key) => 'role="button" tabindex="0" @click="show(\''.$key.'\')" @keydown.enter="show(\''.$key.'\')"';
        $click = 'cursor-pointer hover:underline decoration-dotted underline-offset-4';
        $result = $sum($data['result']);
    @endphp

    <form method="GET" action="{{ route('analytics.show', 'profit') }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3">
        <label><span class="field-label">{{ __('Ayından') }}</span><input type="month" name="from" value="{{ $from }}" class="input font-mono"></label>
        <label><span class="field-label">{{ __('Ayınadək') }}</span><input type="month" name="to" value="{{ $to }}" class="input font-mono"></label>
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        <div class="flex flex-wrap gap-1 lg:ml-auto">
            @foreach($quick as $ql => [$qf, $qt])
                <a href="{{ route('analytics.show', ['profit', 'from' => $qf, 'to' => $qt]) }}" @class(['btn btn-sm', 'btn-ghost' => ! ($qf === $from && $qt === $to), 'bg-brand-soft text-brand-ink' => $qf === $from && $qt === $to])>{{ $ql }}</a>
            @endforeach
        </div>
    </form>

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
        <div class="card p-5" style="--i:0"><div class="text-xs text-muted">{{ __('Gəlirlər') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($sum($data['in'])) }}</div><div class="text-[11px] text-faint">{{ $period }}</div></div>
        <div class="card p-5" style="--i:1"><div class="text-xs text-muted">{{ __('Xərclər') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($sum($data['out'])) }}</div><div class="text-[11px] text-faint">{{ $period }}</div></div>
        <div class="card p-5" style="--i:2"><div class="text-xs text-muted">{{ __('Mənfəət (zərər)') }}</div><div @class(['mt-1 text-2xl font-semibold font-mono tabular-nums', 'text-success' => $result > 0, 'text-danger' => $result < 0])>{{ money($result) }}</div><div class="text-[11px] text-faint">{{ __('gəlirlər − xərclər') }}</div></div>
        <div class="card p-5" style="--i:3"><div class="text-xs text-muted">{{ __('MV 20%') }}</div><div class="mt-1 text-2xl font-semibold font-mono tabular-nums">{{ money($sum($data['tax'])) }}</div><div class="text-[11px] text-faint">{{ __('mənfəət × 20%') }}</div></div>
    </div>

    <section class="card overflow-hidden" x-data="{ open: null, why: @js($why), show(k) { if (this.why[k]) this.open = k; } }">
        <div class="overflow-auto max-h-[80vh]">
            <table class="text-[13px] border-separate border-spacing-0 min-w-full">
                <thead class="sticky top-0 z-20">
                    <tr class="bg-[#9a4a0c] text-white">
                        <th class="sticky left-0 z-30 bg-[#9a4a0c] px-3 py-3 text-left text-sm font-semibold w-24">{{ __('Hesab №-si') }}</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold min-w-[320px]">{{ __('Gəlir-Xərc maddələri') }}</th>
                        @foreach($months as $m)<th class="px-4 py-3 text-right font-semibold whitespace-nowrap min-w-[115px] border-l border-white/15">{{ $label($m) }}</th>@endforeach
                        <th class="px-4 py-3 text-right font-semibold whitespace-nowrap min-w-[130px] border-l border-white/30 bg-[#7c3a08]">{{ __('Cəmi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(['in' => [__('GƏLİRLƏR'), 'text-success', 'bg-success-soft'], 'out' => [__('XƏRCLƏR'), 'text-danger', 'bg-danger-soft']] as $side => [$heading, $tone, $bg])
                        <tr><td colspan="{{ count($months) + 3 }}" class="sticky left-0 px-4 pt-5 pb-2 text-xs font-bold uppercase tracking-wider {{ $tone }}">{{ $heading }}</td></tr>
                        @foreach($lines as $code => $l)
                            @continue($l['side'] !== $side)
                            <tr class="group">
                                <td class="sticky left-0 z-10 bg-surface group-hover:bg-surface-2 px-3 py-2 border-b border-line font-mono text-xs text-muted">{{ $code }}</td>
                                <td class="px-4 py-2 border-b border-line group-hover:bg-surface-2">{{ $l['label'] }}</td>
                                @foreach($months as $m)
                                    @php $v = $l['values'][$m]; @endphp
                                    <td @class(['px-4 py-2 text-right font-mono tabular-nums border-b border-line group-hover:bg-surface-2', 'text-faint' => abs($v) < 0.005, 'text-danger' => $v < -0.005, $click => abs($v) >= 0.005])
                                        @if(abs($v) >= 0.005) {!! $tip("$code|$m") !!} @endif>{{ $n($v) }}</td>
                                @endforeach
                                <td @class(['px-4 py-2 text-right font-mono tabular-nums font-semibold border-b border-line bg-surface-2/60', 'text-danger' => $l['total'] < -0.005, $click => abs($l['total']) >= 0.005])
                                    @if(abs($l['total']) >= 0.005) {!! $tip("$code|all") !!} @endif>{{ $n($l['total']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="{{ $bg }} font-semibold">
                            <td class="sticky left-0 z-10 {{ $bg }} px-3 py-2.5"></td>
                            <td class="px-4 py-2.5">{{ __('CƏMİ') }}</td>
                            @foreach($months as $m)<td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ $n($data[$side][$m]) }}</td>@endforeach
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ $n($sum($data[$side])) }}</td>
                        </tr>
                    @endforeach
                    <tr><td colspan="{{ count($months) + 3 }}" class="h-4"></td></tr>
                    <tr class="bg-[#9a4a0c]/10 font-bold">
                        <td class="sticky left-0 z-10 bg-[#f6ebe2] dark:bg-[#3a2416] px-3 py-3"></td>
                        <td class="px-4 py-3">{{ __('HESABAT DÖVRÜNDƏ MƏNFƏƏT (ZƏRƏR)') }}</td>
                        @foreach($months as $m)<td @class(['px-4 py-3 text-right font-mono tabular-nums', 'text-success' => $data['result'][$m] > 0.005, 'text-danger' => $data['result'][$m] < -0.005])>{{ $n($data['result'][$m]) }}</td>@endforeach
                        <td @class(['px-4 py-3 text-right font-mono tabular-nums', 'text-success' => $result > 0, 'text-danger' => $result < 0])>{{ $n($result) }}</td>
                    </tr>
                    <tr>
                        <td class="sticky left-0 z-10 bg-surface px-3 py-2"></td>
                        <td class="px-4 py-2 text-muted">{{ __('MV 20%') }}</td>
                        @foreach($months as $m)<td class="px-4 py-2 text-right font-mono tabular-nums text-muted">{{ $n($data['tax'][$m]) }}</td>@endforeach
                        <td class="px-4 py-2 text-right font-mono tabular-nums text-muted">{{ $n($sum($data['tax'])) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="px-5 py-3 border-t border-line text-[11px] text-muted leading-relaxed">
            <span class="font-medium text-ink-2">{{ __('Rəqəmə toxunun — necə yarandığı açılacaq.') }}</span>
            {{ __('217.2 — hər Trade-in satışı akt gününün CBAR kursu ilə (H × BK), aktın ayında. 232.1 — həmin Trade-lərin alışı (D × BJ) və logistikası akt gününün kursu ilə. 214 / 219.3 — 1C metodu ilə məzənnə fərqi yazılışları. 221.13 — Cash flow-un «Kurs fərqi» (bank ilə CBAR fərqi) və bank komissiyası xərcləri. Digər xərclər kateqoriyasının «MZ sətri»nə görə (Xərclər → Kateqoriyalar), tarixinə görə.') }}
        </p>

        <div x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="open = null">
            <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = null"></div>
            <template x-if="open">
                <div class="relative card w-full max-w-4xl max-h-[88vh] flex flex-col overflow-hidden">
                    <header class="flex items-start justify-between gap-4 px-5 py-4 border-b border-line">
                        <div class="min-w-0">
                            <div class="text-[11px] uppercase tracking-wider text-muted" x-text="why[open].s"></div>
                            <h3 class="text-lg font-semibold" x-text="why[open].h"></h3>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" @click="open = null" aria-label="{{ __('Bağla') }}"><x-icon name="x" class="size-5"/></button>
                    </header>
                    <div class="overflow-auto flex-1">
                        <table class="table-g text-[13px]">
                            <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Mənbə') }}</th><th>{{ __('Hesablama') }}</th><th class="!text-right">AZN</th></tr></thead>
                            <tbody>
                                <template x-for="(r, i) in why[open].rows" :key="i">
                                    <tr>
                                        <td class="font-mono whitespace-nowrap text-muted" x-text="r.d"></td>
                                        <td><template x-if="r.u"><a :href="r.u" class="hover:text-brand-ink underline decoration-dotted underline-offset-2" x-text="r.t"></a></template><span x-show="!r.u" x-text="r.t"></span></td>
                                        <td class="font-mono text-[12px] text-muted" x-text="r.c || ''"></td>
                                        <td class="num font-semibold whitespace-nowrap" :class="r.v < 0 ? 'text-danger' : ''" x-text="(r.v < 0 ? '−' : '') + glaustFmt.fmt(Math.abs(r.v), 2)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <footer class="flex items-center justify-between gap-3 px-5 py-3 border-t border-line bg-surface-2 text-sm">
                        <span class="text-muted" x-text="why[open].rows.length + ' {{ __('sətir') }}'"></span>
                        <span class="font-semibold">{{ __('Cəmi') }}: <span class="font-mono" x-text="(why[open].v < 0 ? '−' : '') + glaustFmt.fmt(Math.abs(why[open].v), 2) + ' ₼'"></span></span>
                    </footer>
                </div>
            </template>
        </div>
    </section>
</x-layouts.app>
