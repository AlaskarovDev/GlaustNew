@php $cur = $account->currency; $q = array_filter(['from' => $from?->toDateString(), 'to' => $to?->toDateString()]); @endphp
<x-layouts.app :title="__('Çıxarış — ').$account->name" wide>
    <x-page-header :title="__('Hesab çıxarışı — ').$account->name" :back="route('bank.accounts.index')"
                   :subtitle="$account->bank_name.' · '.$cur.($account->iban ? ' · '.trim(chunk_split($account->iban, 4, ' ')) : '')">
        <x-slot:actions>
            <a href="{{ route('bank.accounts.statement', [$account] + $q + ['format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> {{ __('Excel') }}</a>
            <a href="{{ route('bank.accounts.statement', [$account] + $q + ['format' => 'pdf']) }}" class="btn btn-secondary"><x-icon name="file-pdf" class="size-4 text-danger"/> PDF</a>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
        <x-field :label="__('Başlanğıc tarix')" name="from"><input type="date" name="from" value="{{ $from?->toDateString() }}" class="input"></x-field>
        <x-field :label="__('Son tarix')" name="to"><input type="date" name="to" value="{{ $to?->toDateString() }}" class="input"></x-field>
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        @if($q)<a href="{{ route('bank.accounts.statement', $account) }}" class="btn btn-ghost">{{ __('Bütün dövr') }}</a>@endif
        <div class="flex flex-wrap gap-1.5 ml-auto">
            @foreach(['Bu ay' => [today()->startOfMonth(), today()], 'Keçən ay' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()], 'Bu il' => [today()->startOfYear(), today()]] as $l => [$f, $t])
                <a href="{{ route('bank.accounts.statement', [$account, 'from' => $f->toDateString(), 'to' => $t->toDateString()]) }}" class="btn btn-ghost btn-sm">{{ __($l) }}</a>
            @endforeach
        </div>
    </form>

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-5 stagger">
        <div class="card p-4" style="--i:0"><div class="text-xs text-muted">{{ __('Əvvəlki qalıq ·') }} {{ $from ? azdate($from) : __('açılış') }}</div><div class="text-xl font-semibold font-mono">{{ money($opening, $cur) }}</div></div>
        <div class="card p-4" style="--i:1"><div class="text-xs text-muted">{{ __('Mədaxil') }}</div><div class="text-xl font-semibold font-mono text-success">+{{ money($in, $cur) }}</div><div class="text-[11px] text-muted">{{ $rows->where('direction', 'in')->count() }} {{ __('əməliyyat') }}</div></div>
        <div class="card p-4" style="--i:2"><div class="text-xs text-muted">{{ __('Məxaric') }}</div><div class="text-xl font-semibold font-mono text-danger">−{{ money($out, $cur) }}</div><div class="text-[11px] text-muted">{{ $rows->where('direction', 'out')->count() }} {{ __('əməliyyat') }}</div></div>
        <div class="card p-4" style="--i:3"><div class="text-xs text-muted">{{ __('Son qalıq ·') }} {{ $to ? azdate($to) : __('bu gün') }}</div><div @class(['text-xl font-semibold font-mono', 'text-danger' => $closing < 0])>{{ money($closing, $cur) }}</div></div>
    </div>

    <section class="card overflow-hidden">
        <header class="px-5 py-3 border-b border-line text-sm text-muted">{{ __('Dövr:') }} <span class="text-ink">{{ $period }}</span> · {{ $rows->count() }} {{ __('əməliyyat') }}</header>
        @if($rows->isEmpty())
            <x-empty icon="list" :title="__('Bu dövrdə əməliyyat yoxdur')" :text="__('Başqa tarix aralığı seçin.')"/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack text-[13px]">
                    <thead><tr>
                        <th>{{ __('Tarix') }}</th><th>{{ __('Qarşı tərəf') }}</th><th>{{ __('Təyinat') }}</th><th>{{ __('İstinad') }}</th>
                        <th class="!text-right">{{ __('Mədaxil') }}</th><th class="!text-right">{{ __('Məxaric') }}</th><th class="!text-right">{{ __('Qalıq') }}</th><th class="!text-right">{{ __('Kurs / AZN') }}</th>
                    </tr></thead>
                    <tbody>
                    <tr class="bg-surface-2/60"><td colspan="6" class="text-muted text-xs">{{ __('Əvvəlki qalıq') }}</td><td class="num font-medium">{{ money($opening) }}</td><td></td></tr>
                    @foreach($rows as $t)
                        <tr>
                            <td data-label="{{ __('Tarix') }}" class="font-mono text-xs whitespace-nowrap"><a href="{{ route('bank.transactions.show', $t) }}" class="hover:text-brand-ink">{{ azdate($t->transaction_date) }}</a></td>
                            <td data-label="{{ __('Qarşı tərəf') }}">{{ $t->counterparty?->name ?? ($t->kind !== 'regular' ? __('Daxili köçürmə') : '—') }}</td>
                            <td data-label="{{ __('Təyinat') }}" class="max-w-[320px]">
                                <span class="line-clamp-2">{{ $t->purpose ?? '—' }}</span>
                                <div class="flex flex-wrap gap-1 mt-0.5">
                                    @if($t->deal)<a href="{{ route('deals.show', [$t->deal_id, 'tab' => 'income']) }}" class="badge badge-teal !h-5">{{ $t->deal->code }}</a>@endif
                                    @if($t->category)<span class="badge badge-slate !h-5">{{ $t->category->name }}</span>@endif
                                </div>
                            </td>
                            <td data-label="{{ __('İstinad') }}" class="font-mono text-xs">{{ $t->reference ?? '—' }}</td>
                            <td data-label="{{ __('Mədaxil') }}" class="num text-success">{{ $t->direction === 'in' ? money($t->amount) : '' }}</td>
                            <td data-label="{{ __('Məxaric') }}" class="num text-danger">{{ $t->direction === 'out' ? money($t->amount) : '' }}</td>
                            <td data-label="{{ __('Qalıq') }}" @class(['num font-medium', 'text-danger' => $t->running_balance < 0])>{{ money($t->running_balance) }}</td>
                            <td data-label="{{ __('Kurs / AZN') }}" class="num text-xs">@if($cur !== 'AZN'){{ rate_fmt($t->applied_rate) }}<div class="text-faint">{{ money($t->amount_azn) }} ₼</div>@else — @endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="bg-surface-2 font-semibold">
                        <td class="px-4 py-3" colspan="4">{{ __('Cəmi · son qalıq') }}</td>
                        <td class="px-4 py-3 text-right font-mono text-success">{{ money($in) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-danger">{{ money($out) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ money($closing) }}</td><td></td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
