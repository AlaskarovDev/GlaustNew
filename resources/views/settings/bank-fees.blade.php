<x-layouts.app :title="__('Bank komissiyası')">
    <x-page-header :title="__('Tənzimləmələr')" icon="settings"/>
    @include('settings._nav')
    @php
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', ''), '0'), ',');
        $num = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
    @endphp
    <div class="grid lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
        <section class="card overflow-hidden">
            <header class="px-5 py-4 border-b border-line">
                <h2 class="text-sm font-semibold">{{ __('Bankın köçürmə komissiyası') }}</h2>
                <p class="text-xs text-muted mt-1">{{ __('Komissiya = məbləğin faizi, amma ən az və ən çox həddin arasında. Ödənişə həmin tarixdə qüvvədə olan qayda tətbiq olunur: əvvəl ödənişin getdiyi hesabın bankının qaydası, yoxdursa «Bütün banklar» qaydası. Valyutanın qaydası yoxdursa, EUR qaydası tətbiq olunur (hədlər CBAR kursu ilə çevrilir). Hər şirkətin qaydaları ayrıdır.') }}</p>
            </header>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-surface-2 text-xs text-muted">
                        <tr>
                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Bank') }}</th>
                            <th class="px-3 py-2.5 text-left font-semibold">{{ __('Valyuta') }}</th>
                            <th class="px-3 py-2.5 text-left font-semibold">{{ __('Tarixdən') }}</th>
                            <th class="px-3 py-2.5 text-right font-semibold">{{ __('Faiz') }}</th>
                            <th class="px-3 py-2.5 text-right font-semibold">{{ __('Ən az') }}</th>
                            <th class="px-3 py-2.5 text-right font-semibold">{{ __('Ən çox') }}</th>
                            <th class="px-3 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach($rules as $r)
                            @php
                                $f = $inForce($r);
                                $state = $r->valid_from->toDateString() > $today ? 'future'
                                    : ($f && $f['from'] === $r->valid_from->toDateString() && $f['bank'] === \App\Models\BankFeeRule::bankKey($r->bank_name) ? 'now' : 'old');
                            @endphp
                            <tr @class(['text-muted' => $state === 'old'])>
                                <td class="px-4 py-2.5">{{ $r->bank_name ?? __('Bütün banklar') }}</td>
                                <td class="px-3 py-2.5 font-mono">{{ $r->currency }}</td>
                                <td class="px-3 py-2.5 font-mono whitespace-nowrap">{{ $r->valid_from->format('d.m.Y') }}
                                    @if($state === 'now')<span class="badge badge-green ml-1">{{ __('qüvvədədir') }}</span>
                                    @elseif($state === 'future')<span class="badge badge-amber ml-1">{{ __('gələcək') }}</span>@endif</td>
                                <td class="px-3 py-2.5 font-mono text-right">{{ $pct($r->percent) }}%</td>
                                <td class="px-3 py-2.5 font-mono text-right">{{ $num($r->minimum) }}</td>
                                <td class="px-3 py-2.5 font-mono text-right">{{ $num($r->maximum) }}</td>
                                <td class="px-3 py-2.5 text-right">@can('settings.update')<x-delete-form :action="route('settings.bank-fees.destroy', $r)" label="" :message="__('Bu qayda silinsin? Ondan əvvəlki qayda qüvvədə olacaq.')"/>@endcan</td>
                            </tr>
                        @endforeach
                        @foreach($system as $cur => $s)
                            <tr class="bg-surface-2/50">
                                <td class="px-4 py-2.5">{{ __('Bütün banklar') }} <span class="text-[11px] text-faint">{{ __('· sistem qaydası') }}</span></td>
                                <td class="px-3 py-2.5 font-mono">{{ $cur }}</td>
                                <td class="px-3 py-2.5 text-xs text-muted">{{ __('başlanğıcdan') }}</td>
                                <td class="px-3 py-2.5 font-mono text-right">{{ $pct($s['percent']) }}%</td>
                                <td class="px-3 py-2.5 font-mono text-right">{{ $num($s['minimum']) }}</td>
                                <td class="px-3 py-2.5 font-mono text-right">{{ $num($s['maximum'] ?? null) }}</td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @can('settings.update')
            <form method="POST" action="{{ route('settings.bank-fees.store') }}" class="card p-5 space-y-4">
                @csrf
                <h2 class="text-sm font-semibold">{{ __('Yeni qayda') }}</h2>
                <x-select name="bank_name" :label="__('Bank')" :options="$banks->mapWithKeys(fn ($b) => [$b => $b])->all()" :placeholder="__('Bütün banklar')"/>
                <x-select name="currency" :label="__('Valyuta')" :options="array_combine($currencies, $currencies)" value="EUR" required/>
                <x-input name="valid_from" type="date" :label="__('Bu tarixdən etibarən')" :value="$today" required :hint="__('Bu tarixdən sonrakı ödənişlər bu qayda ilə hesablanır; əvvəlkilər köhnə qayda ilə qalır.')"/>
                <x-input name="percent" :label="__('Faiz (%)')" inputmode="decimal" required placeholder="0.25"/>
                <div class="grid grid-cols-2 gap-3">
                    <x-input name="minimum" :label="__('Ən az')" inputmode="decimal" placeholder="25"/>
                    <x-input name="maximum" :label="__('Ən çox')" inputmode="decimal" placeholder="300"/>
                </div>
                <p class="text-[11px] text-muted">{{ __('Ən az / ən çox — seçilmiş valyutada. «Ən çox» boş qalsa, yuxarı hədd yoxdur.') }}</p>
                <button class="btn btn-primary w-full"><x-icon name="plus" class="size-4"/> {{ __('Əlavə et') }}</button>
            </form>
        @endcan
    </div>
</x-layouts.app>
