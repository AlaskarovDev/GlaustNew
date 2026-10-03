{{-- Payments of one direction: $dir = in (from the buyer) or out (to the seller). --}}
@php
    $out = $dir === 'out';
    $list = $deal->payments;
    $party = $out ? $deal->supplier?->name : $deal->counterparty?->name;
@endphp
<section class="card overflow-hidden mb-6">
    <header class="px-5 py-4 border-b border-line">
        <h2 class="text-base font-semibold">{{ $out ? 'Satıcıya ödənişlər' : 'Daxil olan ödənişlər' }} <span class="text-muted font-mono font-normal text-sm">{{ $list->count() }}</span></h2>
        <p class="text-xs text-muted">Hər ödəniş «Bank əməliyyatları» bölməsində və hesab çıxarışında da görünür</p>
    </header>
    @if($list->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="table-g table-stack">
                <thead><tr><th>Tarix</th><th>{{ $out ? 'Kimə' : 'Kimdən' }}</th><th class="!text-right">Məbləğ</th><th>Hesab</th><th class="!text-right">CBAR kursu</th><th class="!text-right">Bankın kursu</th><th class="!text-right">AZN (bank)</th><th class="!text-right">Fərq</th><th class="w-24"></th></tr></thead>
                <tbody>
                @foreach($list as $p)
                    @php $diff = round((float) $p->amount_azn - (float) $p->cbar_amount_azn, 2); @endphp
                    <tr>
                        <td data-label="Tarix" class="font-mono text-xs">{{ azdate($p->transaction_date) }}</td>
                        <td data-label="Tərəf">{{ $party }}@if($p->reference)<div class="text-[11px] text-muted font-mono">{{ $p->reference }}</div>@endif</td>
                        <td data-label="Məbləğ" @class(['num font-medium', 'text-danger' => $out, 'text-success' => ! $out])>{{ $out ? '−' : '+' }}{{ money($p->amount, $p->currency) }}</td>
                        <td data-label="Hesab" class="text-sm">{{ $p->account?->name }}<div class="text-[11px] text-muted">{{ $p->account?->bank_name }}</div></td>
                        <td data-label="CBAR kursu" class="num text-xs">{{ rate_fmt($p->cbar_rate) }}</td>
                        <td data-label="Bankın kursu" @class(['num text-xs', 'text-brand-ink font-medium' => (float) $p->applied_rate !== (float) $p->cbar_rate])>{{ rate_fmt($p->applied_rate) }}</td>
                        <td data-label="AZN (bank)" class="num">{{ money($p->amount_azn) }}<div class="text-[11px] text-faint">CBAR: {{ money($p->cbar_amount_azn) }}</div></td>
                        <td data-label="Fərq" @class(['num text-xs', 'text-danger' => $diff < 0, 'text-success' => $diff > 0])>{{ $diff > 0 ? '+' : '' }}{{ money($diff) }}</td>
                        <td class="text-right whitespace-nowrap">
                            @can('bank.view')<a href="{{ route('bank.transactions.show', $p) }}" class="btn btn-ghost btn-icon btn-sm" aria-label="Bank əməliyyatına bax" title="Bank əməliyyatı"><x-icon name="external" class="size-4"/></a>@endcan
                            @can('bank.delete')
                                <form method="POST" action="{{ route('deals.payments.destroy', [$deal, $p]) }}" class="inline" data-confirm="Ödəniş ({{ money($p->amount, $p->currency) }}) silinsin? Bank əməliyyatı da silinəcək." data-confirm-action="Sil">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="Ödənişi sil"><x-icon name="trash" class="size-4"/></button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="px-5 py-5 text-sm text-muted">{{ $out ? 'Satıcıya hələ ödəniş edilməyib.' : 'Hələ ödəniş daxil olmayıb.' }}</p>
    @endif
</section>
