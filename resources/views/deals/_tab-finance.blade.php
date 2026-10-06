{{-- Maliyyə icmalı: the Trade's money per currency (owed, bought for it, spent, what is left to buy or was bought too much) and every movement. --}}
@php
    $fx = \App\Support\DealFinance::currencies($deal);
    $moves = \App\Support\DealFinance::movements($deal);
    $m = fn ($v, $c) => abs($v) < 0.005 ? '—' : money($v, $c);
@endphp

<section class="card overflow-hidden mb-6">
    <header class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-line">
        <div>
            <h2 class="text-base font-semibold">{{ __('Valyuta üzrə vəziyyət') }}</h2>
            <p class="text-xs text-muted">{{ __('Trade-in ödəməli olduğu, onun üçün alınan və xərclənən valyuta — nə qədər daha lazımdır, nə qədər artıq alınıb') }}</p>
        </div>
        @can('bank.create')
            <a href="{{ route('bank.exchanges.index') }}" class="btn btn-secondary btn-sm"><x-icon name="transfer" class="size-4"/> {{ __('Valyuta alış-satışı') }}</a>
        @endcan
    </header>
    @if($fx)
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr>
                    <th>{{ __('Valyuta') }}</th><th class="!text-right">{{ __('Ödəməliyik') }}</th><th class="!text-right">{{ __('Trade üçün alınıb') }}</th>
                    <th class="!text-right">{{ __('Xərclənib') }}</th><th class="!text-right">{{ __('Əldə qalıb') }}</th><th class="!text-right">{{ __('Daha alınmalı') }}</th><th class="!text-right">{{ __('Artıq alınıb') }}</th>
                </tr></thead>
                <tbody>
                @foreach($fx as $r)
                    <tr>
                        <td data-label="{{ __('Valyuta') }}" class="font-mono font-semibold">{{ $r['currency'] }}</td>
                        <td data-label="{{ __('Ödəməliyik') }}" class="num text-danger">{{ $m($r['due'], $r['currency']) }}</td>
                        <td data-label="{{ __('Trade üçün alınıb') }}" class="num">{{ $m($r['acquired'], $r['currency']) }}@if($r['given'] > 0)<div class="text-[11px] text-faint">{{ __('satılıb') }}: {{ money($r['given'], $r['currency']) }}</div>@endif</td>
                        <td data-label="{{ __('Xərclənib') }}" class="num">{{ $m($r['spent'], $r['currency']) }}</td>
                        <td data-label="{{ __('Əldə qalıb') }}" class="num">{{ $r['acquired'] > 0 ? $m(max(0, $r['available']), $r['currency']) : '—' }}</td>
                        <td data-label="{{ __('Daha alınmalı') }}" @class(['num font-semibold', 'text-saffron' => $r['to_buy'] > 0])>{{ $m($r['to_buy'], $r['currency']) }}</td>
                        <td data-label="{{ __('Artıq alınıb') }}" @class(['num font-semibold', 'text-brand-ink' => $r['surplus'] > 0])>{{ $m($r['surplus'], $r['currency']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @foreach($fx as $r)
            @if($r['surplus'] > 0)
                <p class="px-5 py-2 border-t border-line text-sm text-brand-ink flex items-center gap-2"><x-icon name="info" class="size-4"/>
                    {{ __(':amount bu Trade üçün artıq alınıb — ödənişlərdən artıq qalıb.', ['amount' => money($r['surplus'], $r['currency'])]) }}</p>
            @elseif($r['to_buy'] > 0 && $r['acquired'] > 0)
                <p class="px-5 py-2 border-t border-line text-sm text-saffron flex items-center gap-2"><x-icon name="clock" class="size-4"/>
                    {{ __(':bought alınıb, :need da lazım olacaq.', ['bought' => money($r['acquired'], $r['currency']), 'need' => money($r['to_buy'], $r['currency'])]) }}</p>
            @endif
        @endforeach
    @else
        <p class="px-5 py-5 text-sm text-muted">{{ __('Bu Trade üzrə hələ xarici valyutada öhdəlik və ya hərəkət yoxdur.') }}</p>
    @endif
</section>

<section class="card overflow-hidden">
    <header class="px-5 py-4 border-b border-line">
        <h2 class="text-base font-semibold">{{ __('Maliyyə əməliyyatları') }} <span class="text-muted font-mono font-normal text-sm">{{ count($moves) }}</span></h2>
        <p class="text-xs text-muted">{{ __('Mədaxillər, satıcıya və logistikaya ödənişlər, bank komissiyaları, xərclər və valyuta alış-satışı — tarixə görə') }}</p>
    </header>
    @if($moves)
        <div class="overflow-x-auto">
            <table class="table-g table-stack text-sm">
                <thead><tr><th class="w-28">{{ __('Tarix') }}</th><th class="w-44">{{ __('Əməliyyat') }}</th><th>{{ __('Təsvir') }}</th><th class="!text-right">{{ __('Daxil') }}</th><th class="!text-right">{{ __('Çıxış') }}</th></tr></thead>
                <tbody>
                @foreach($moves as $mv)
                    <tr>
                        <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($mv['date']) }}</td>
                        <td data-label="{{ __('Əməliyyat') }}"><span @class(['badge', 'badge-green' => $mv['tone'] === 'success', 'badge-rose' => $mv['tone'] === 'danger', 'badge-teal' => $mv['tone'] === 'brand', 'badge-slate' => $mv['tone'] === 'muted'])>{{ $mv['kind'] }}</span></td>
                        <td data-label="{{ __('Təsvir') }}" class="text-ink-2">{{ $mv['text'] }}</td>
                        <td data-label="{{ __('Daxil') }}" class="num text-success">{{ $mv['in'] !== null ? '+'.money($mv['in'], $mv['cur']) : '' }}</td>
                        <td data-label="{{ __('Çıxış') }}" class="num text-danger">{{ $mv['outAmt'] !== null ? '−'.money($mv['outAmt'], $mv['cur']) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="px-5 py-5 text-sm text-muted">{{ __('Hələ maliyyə əməliyyatı yoxdur.') }}</p>
    @endif
</section>
