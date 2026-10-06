{{-- Hərəkətlər: what the party owes us / we owe it, per currency, entry by entry. --}}
<x-layouts.app :title="__('Hərəkətlər').' · '.$counterparty->name" wide>
    <x-page-header :title="$counterparty->name" :subtitle="__('Hərəkətlər — borc və alacaq').' · '.$counterparty->typeLabel()" :back="route('counterparties.show', $counterparty)" icon="list"/>

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
        @forelse($balances as $cur => $bal)
            <div class="card p-4">
                <div class="text-xs text-muted">{{ $bal > 0 ? __('Bizə borcludur') : __('Biz borcluyuq') }} · {{ $cur }}</div>
                <div @class(['mt-1 text-2xl font-semibold font-mono', 'text-success' => $bal > 0, 'text-danger' => $bal < 0])>{{ money(abs($bal), $cur) }}</div>
            </div>
        @empty
            <div class="card p-4 sm:col-span-2 xl:col-span-4 text-sm text-muted flex items-center gap-2"><x-icon name="check-circle" class="size-5 text-success"/> {{ __('Borc yoxdur — hesablaşma bağlıdır.') }}</div>
        @endforelse
    </div>

    <section class="card overflow-hidden">
        <header class="px-5 py-4 border-b border-line">
            <h2 class="text-base font-semibold">{{ __('Hərəkətlər') }} <span class="text-muted font-mono font-normal text-sm">{{ count($entries) }}</span></h2>
            <p class="text-xs text-muted">{{ __('Debet — bizə borcu artır (Commercial Invoice, bizim ödənişimiz); Kredit — bizim borcumuz artır (satıcı və logistika fakturası, onun ödənişi). Hər valyuta ayrıca.') }}</p>
        </header>
        @if($entries)
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-sm">
                    <thead><tr><th class="w-28">{{ __('Tarix') }}</th><th>{{ __('Sənəd') }}</th><th>{{ __('Təsvir') }}</th><th class="!text-right">{{ __('Debet') }}</th><th class="!text-right">{{ __('Kredit') }}</th><th class="!text-right">{{ __('Qalıq') }}</th></tr></thead>
                    <tbody>
                    @foreach(array_reverse($entries) as $row)
                        <tr>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($row['date']) }}</td>
                            <td data-label="{{ __('Sənəd') }}">@if($row['url'])<a href="{{ $row['url'] }}" class="font-medium hover:text-brand-ink">{{ $row['doc'] }}</a>@else<span class="font-medium">{{ $row['doc'] }}</span>@endif</td>
                            <td data-label="{{ __('Təsvir') }}" class="text-ink-2">{{ $row['text'] }}</td>
                            <td data-label="{{ __('Debet') }}" class="num">{{ $row['debit'] ? money($row['debit'], $row['cur']) : '' }}</td>
                            <td data-label="{{ __('Kredit') }}" class="num">{{ $row['credit'] ? money($row['credit'], $row['cur']) : '' }}</td>
                            <td data-label="{{ __('Qalıq') }}" @class(['num font-medium', 'text-success' => $row['balance'] > 0, 'text-danger' => $row['balance'] < 0])>{{ abs($row['balance']) < 0.005 ? '0' : ($row['balance'] > 0 ? '+' : '−').money(abs($row['balance']), $row['cur']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-5 py-5 text-sm text-muted">{{ __('Bu tərəflə hələ hərəkət yoxdur.') }}</p>
        @endif
    </section>
</x-layouts.app>
