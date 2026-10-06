{{-- Our payments to the seller with the CBAR / bank rates, the debit and the bank fee. --}}
@php $list = $deal->supplierPayments; @endphp
<section class="card overflow-hidden mb-6">
    <header class="px-5 py-4 border-b border-line">
        <h2 class="text-base font-semibold">{{ __('Satıcıya ödənişlər') }} <span class="text-muted font-mono font-normal text-sm">{{ $list->count() }}</span></h2>
        <p class="text-xs text-muted">{{ __('Hər ödəniş üzrə CBAR və bank kursu, hesabdan silinən məbləğ və bank komissiyası saxlanılır') }}</p>
    </header>
    @if($list->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="table-g table-stack text-[13px]">
                <thead><tr><th>{{ __('Tarix') }}</th><th class="!text-right">{{ __('Ödəniş') }}</th><th>{{ __('Hesab') }}</th><th class="!text-right">{{ __('CBAR / bank kursu') }}</th><th class="!text-right">{{ __('Hesabdan') }}</th><th class="!text-right">{{ __('Komissiya') }}</th><th class="!text-right">{{ __('CBAR fərqi') }}</th><th class="w-20"></th></tr></thead>
                <tbody>
                @foreach($list as $p)
                    <tr>
                        <td data-label="{{ __('Tarix') }}" class="font-mono text-xs">{{ azdate($p->payment_date) }}@if($p->reference)<div class="text-faint">{{ $p->reference }}</div>@endif</td>
                        <td data-label="{{ __('Ödəniş') }}" class="num font-medium text-danger">−{{ money($p->amount, $p->currency) }}</td>
                        <td data-label="{{ __('Hesab') }}" class="text-sm">{{ $p->account?->name }}<div class="text-[11px] text-muted">{{ $p->account?->bank_name }}</div></td>
                        <td data-label="{{ __('Kurs') }}" class="num text-xs">@if($p->currency !== $p->account_currency){{ rate_fmt($p->cbar_cross) }}<div class="font-medium text-ink">{{ rate_fmt($p->bank_rate) }}</div>@else — @endif</td>
                        <td data-label="{{ __('Hesabdan') }}" class="num">{{ money($p->totalDebit(), $p->account_currency) }}</td>
                        <td data-label="{{ __('Komissiya') }}" class="num text-xs">{{ money($p->fee_amount, $p->currency) }}@if($p->feeFromOtherAccount())<div class="text-faint">{{ money($p->fee_account_amount, $p->feeCurrency()) }} · {{ $p->feeAccount?->name }}</div>@elseif($p->currency !== $p->account_currency)<div class="text-faint">{{ money($p->fee_account_amount, $p->account_currency) }}</div>@endif</td>
                        <td data-label="{{ __('CBAR fərqi') }}" @class(['num text-xs', 'text-danger' => $p->difference > 0, 'text-success' => $p->difference < 0])>{{ (float) $p->difference ? ($p->difference > 0 ? '−' : '+').money(abs($p->difference), $p->account_currency) : '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            @if($p->transaction_id)@can('bank.view')<a href="{{ route('bank.transactions.show', $p->transaction_id) }}" class="btn btn-ghost btn-icon btn-sm" aria-label="{{ __('Bank əməliyyatı') }}" title="{{ __('Bank əməliyyatı') }}"><x-icon name="external" class="size-4"/></a>@endcan @endif
                            @can('bank.delete')
                                <form method="POST" action="{{ route('deals.supplier-payments.destroy', [$deal, $p]) }}" class="inline" data-confirm="{{ __('Ödəniş (:v1) ləğv edilsin? Hesabdan silinmə və komissiya da silinəcək.', ['v1' => money($p->amount, $p->currency)]) }}" data-confirm-action="{{ __('Ləğv et') }}">
                                    @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="{{ __('Ödənişi ləğv et') }}"><x-icon name="trash" class="size-4"/></button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="px-5 py-5 text-sm text-muted">{{ __('Satıcıya hələ ödəniş edilməyib.') }}</p>
    @endif
</section>
