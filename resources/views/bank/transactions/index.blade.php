<x-layouts.app :title="__('Bank əməliyyatları')">
    <x-page-header :title="__('Bank əməliyyatları')" icon="bank" :subtitle="__('Hər əməliyyat öz tarixinin CBAR məzənnəsi ilə AZN-ə çevrilir')">
        <x-slot:actions>
            @can('bank.create')
                <a href="{{ route('bank.transactions.create', ['mode' => 'transfer']) }}" class="btn btn-secondary"><x-icon name="transfer" class="size-4"/> {{ __('Köçürmə') }}</a>
                <a href="{{ route('bank.transactions.create', ['direction' => 'out']) }}" class="btn btn-secondary"><x-icon name="arrow-up-right" class="size-4 text-danger"/> {{ __('Məxaric') }}</a>
                <a href="{{ route('bank.transactions.create', ['direction' => 'in']) }}" class="btn btn-primary"><x-icon name="arrow-down-left" class="size-4"/> {{ __('Mədaxil') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @include('bank._tabs')

    @if($accounts->isNotEmpty())
        <div class="flex gap-4 overflow-x-auto pb-2 mb-5 -mx-4 px-4 sm:mx-0 sm:px-0 snap-x stagger">
            @foreach($accounts as $a)
                @php $bal = $a->currentBalance(); @endphp
                <a href="{{ request()->fullUrlWithQuery(['account_id' => request('account_id') == $a->id ? null : $a->id, 'page' => null]) }}"
                   @class(['card card-hover p-4 min-w-[230px] snap-start shrink-0', '!border-brand ring-1 ring-brand/30' => request('account_id') == $a->id]) style="--i:{{ $loop->index }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium truncate">{{ $a->name }}</span>
                        <span class="badge badge-slate font-mono !h-5">{{ $a->currency }}</span>
                    </div>
                    <div class="text-xs text-muted truncate">{{ $a->bank_name }}</div>
                    <div @class(['mt-3 text-xl font-semibold font-mono tabular', 'text-danger' => $bal < 0])>{{ money($bal, $a->currency) }}</div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="card overflow-hidden">
        <x-filter-bar :table="$table" export-route="bank.transactions.export" export-ability="bank.export" :placeholder="__('Təyinat, sənəd №, kontragent, müqavilə…')"/>
        <div class="grid grid-cols-3 divide-x divide-line border-b border-line text-center">
            <div class="py-3"><div class="text-[11px] uppercase tracking-wider text-muted">{{ __('Mədaxil') }}</div><div class="font-mono font-semibold text-success">{{ money($totals['in']) }}</div></div>
            <div class="py-3"><div class="text-[11px] uppercase tracking-wider text-muted">{{ __('Məxaric') }}</div><div class="font-mono font-semibold text-danger">{{ money($totals['out']) }}</div></div>
            <div class="py-3"><div class="text-[11px] uppercase tracking-wider text-muted">Fərq ({{ $totals['count'] }})</div><div class="font-mono font-semibold">{{ money($totals['in'] - $totals['out']) }}</div></div>
        </div>
        @if($items->isEmpty())
            <x-empty icon="bank" :title="$table->hasActiveFilters() ? 'Heç nə tapılmadı' : 'Hələ əməliyyat yoxdur'" :text="__('Mədaxil, məxaric və köçürmələri daxil edin və ya bank çıxarışını Excel-dən import edin.')"/>
        @else
            <div class="overflow-x-auto">
                <table class="table-g table-stack">
                    <thead><tr>
                        <x-th :table="$table" sort="date">{{ __('Tarix') }}</x-th><th>{{ __('Hesab') }}</th><th>{{ __('Kontragent / təyinat') }}</th><th>{{ __('Kateqoriya') }}</th>
                        <th class="!text-right">{{ __('Məbləğ') }}</th><x-th :table="$table" sort="amount" num>AZN</x-th>
                    </tr></thead>
                    <tbody>
                    @foreach($items as $t)
                        <tr>
                            <td data-label="Tarix" class="font-mono text-xs whitespace-nowrap">{{ azdate($t->transaction_date) }}</td>
                            <td data-label="Hesab" class="text-xs"><div class="text-ink-2">{{ $t->account?->name }}</div><div class="text-muted">{{ $t->account?->bank_name }}</div></td>
                            <td data-label="Təyinat" class="max-w-[320px]">
                                <a href="{{ route('bank.transactions.show', $t) }}" class="block hover:text-brand-ink">
                                    <span class="flex items-center gap-1.5">
                                        @if($t->kind !== 'regular')<span class="badge badge-violet !h-5 !text-[11px]">{{ config('glaust.transaction_kinds.'.$t->kind) }}</span>@endif
                                        <span class="font-medium text-ink truncate">{{ $t->counterparty?->name ?? ($t->purpose ?: '—') }}</span>
                                    </span>
                                    @if($t->counterparty && $t->purpose)<span class="block text-xs text-muted truncate">{{ $t->purpose }}</span>@endif
                                    @if($t->contract)<span class="block text-xs text-muted">Müqavilə {{ $t->contract->number }}</span>@endif
                                </a>
                            </td>
                            <td data-label="Kateqoriya">
                                @if($t->category)<span class="inline-flex items-center gap-1.5 text-xs"><span class="size-2 rounded-full" style="background: {{ $t->category->color ?? '#94a3b8' }}"></span>{{ $t->category->name }}</span>@else<span class="text-faint">—</span>@endif
                            </td>
                            <td data-label="Məbləğ" class="num whitespace-nowrap {{ $t->direction === 'in' ? 'text-success' : 'text-danger' }}">{{ $t->direction === 'in' ? '+' : '−' }}{{ money($t->amount, $t->currency) }}</td>
                            <td data-label="AZN" class="num text-ink-2">
                                {{ money($t->amount_azn) }}
                                @if($t->currency !== 'AZN')<div class="text-[11px] text-faint">{{ rate_fmt($t->applied_rate) }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $items->links() }}
        @endif
    </div>
</x-layouts.app>
