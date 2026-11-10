<x-layouts.app :title="__('Balans')" wide>
    <x-page-header :title="__('Balans')" :icon="$icon" :subtitle="__('Şirkətin BALANS cədvəli kimi: ay sonuna aktivlər və passivlər hesab nömrələri üzrə, AZN')">
        <x-slot:actions>
            <a href="{{ route('analytics.show', ['report' => 'balance', 'month' => $month, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
        </x-slot:actions>
    </x-page-header>

    @include('analytics._bar')

    @php
        $why = [];
        foreach (['a' => $data['assets'], 'l' => $data['liabilities']] as $side => $ls) {
            foreach ($ls as $code => $l) {
                $why["{$side}{$code}"] = ['h' => $code.' · '.$l['label'], 's' => azdate($data['date']), 'rows' => $l['items'], 'v' => $l['value']];
            }
        }
        $tip = fn (string $key) => 'role="button" tabindex="0" @click="show(\''.$key.'\')" @keydown.enter="show(\''.$key.'\')"';
    @endphp

    <p class="text-[11px] text-muted -mt-3 mb-5">{{ __('Valyuta qalıqları, avanslar və borclar :v1 tarixinin CBAR kursu ilə.', ['v1' => azdate($data['date'])]) }}</p>

    <div class="grid lg:grid-cols-2 gap-6 items-start" x-data="{ open: null, why: @js($why), show(k) { if (this.why[k]) this.open = k; } }">
        @foreach(['a' => [__('AKTİVLƏR'), $data['assets'], $data['assetsTotal']], 'l' => [__('PASSİVLƏR'), $data['liabilities'], $data['liabilitiesTotal']]] as $side => [$heading, $ls, $total])
            <section class="card overflow-hidden">
                <table class="table-g text-[13px]">
                    <thead><tr>
                        {{-- the sheet's brown header: on the cells, the table style colours them itself --}}
                        <th class="!bg-[#9a4a0c] !text-white !font-semibold w-20">{{ __('Hesab №-si') }}</th>
                        <th class="!bg-[#9a4a0c] !text-white !font-bold !text-[13px] tracking-wide">{{ $heading }}</th>
                        <th class="!bg-[#9a4a0c] !text-white !font-semibold !text-right whitespace-nowrap">{{ azdate($data['date']) }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($ls as $code => $l)
                            <tr>
                                <td class="font-mono text-xs text-muted">{{ $code }}</td>
                                <td>{{ $l['label'] }}</td>
                                <td @class(['num font-semibold', 'text-faint' => abs($l['value']) < 0.005, 'text-danger' => $l['value'] < -0.005, 'cursor-pointer hover:underline decoration-dotted underline-offset-4' => $l['items']])
                                    @if($l['items']) {!! $tip($side.$code) !!} @endif>{{ abs($l['value']) < 0.005 ? '—' : money($l['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td class="!bg-[#9a4a0c]/10"></td><td class="!bg-[#9a4a0c]/10 font-bold">{{ __('Cəmi') }}</td><td class="!bg-[#9a4a0c]/10 num font-bold whitespace-nowrap">{{ money($total) }}</td></tr></tfoot>
                </table>
            </section>
        @endforeach

        <div class="lg:col-span-2 card p-4 flex flex-wrap items-center gap-3 text-sm {{ abs($data['gap']) < 0.01 ? 'border-success/30 bg-success-soft/40' : 'border-saffron/40 bg-saffron-soft/50' }}">
            <x-icon :name="abs($data['gap']) < 0.01 ? 'check-circle' : 'alert'" class="size-5 shrink-0 {{ abs($data['gap']) < 0.01 ? 'text-success' : 'text-saffron' }}"/>
            <div class="flex-1"><span class="font-semibold">{{ __('Fərq (aktivlər − passivlər)') }}: <span class="font-mono">{{ money($data['gap']) }}</span></span>
                <span class="text-muted"> — {{ __('nizamnamə kapitalı və sistemə yazılmayan hərəkətlər (məs. geri qaytarılan komissiya, uçota alınmayan qalıq). Sıfır olanda balans bağlanır.') }}</span></div>
        </div>

        <div x-cloak x-show="open" class="fixed inset-0 z-[75] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="open = null">
            <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" @click="open = null"></div>
            <template x-if="open">
                <div class="relative card w-full max-w-3xl max-h-[88vh] flex flex-col overflow-hidden">
                    <header class="flex items-start justify-between gap-4 px-5 py-4 border-b border-line">
                        <div class="min-w-0"><div class="text-[11px] uppercase tracking-wider text-muted" x-text="why[open].s"></div><h3 class="text-lg font-semibold" x-text="why[open].h"></h3></div>
                        <button type="button" class="btn btn-ghost btn-icon" @click="open = null" aria-label="{{ __('Bağla') }}"><x-icon name="x" class="size-5"/></button>
                    </header>
                    <div class="overflow-auto flex-1">
                        <table class="table-g text-[13px]">
                            <tbody>
                                <template x-for="(r, i) in why[open].rows" :key="i">
                                    <tr>
                                        <td><template x-if="r.u"><a :href="r.u" class="hover:text-brand-ink underline decoration-dotted underline-offset-2" x-text="r.t"></a></template><span x-show="!r.u" x-text="r.t"></span></td>
                                        <td class="font-mono text-[12px] text-muted" x-text="r.c || ''"></td>
                                        <td class="num font-semibold whitespace-nowrap" :class="r.v < 0 ? 'text-danger' : ''" x-text="(r.v < 0 ? '−' : '') + glaustFmt.fmt(Math.abs(r.v), 2)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <footer class="flex justify-end px-5 py-3 border-t border-line bg-surface-2 text-sm font-semibold">{{ __('Cəmi') }}: <span class="font-mono ml-1" x-text="(why[open].v < 0 ? '−' : '') + glaustFmt.fmt(Math.abs(why[open].v), 2) + ' ₼'"></span></footer>
                </div>
            </template>
        </div>
    </div>
</x-layouts.app>
