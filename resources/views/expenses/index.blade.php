<x-layouts.app :title="__('Xərclər')">
    @php $q = array_filter($filters + ['from' => $from, 'to' => $to], fn ($v) => $v !== null && $v !== ''); unset($q['format']); @endphp
    <x-page-header :title="__('Xərclərin idarəetmə mərkəzi')" icon="receipt" :subtitle="__('Şirkətin xərcləri: kateqoriya, ödənilib / ödənilməyib, nağd və ya hesabdan köçürmə')">
        <x-slot:actions>
            @can('expenses.export')
                <a href="{{ route('expenses.index', $q + ['format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> {{ __('Excel') }}</a>
                <a href="{{ route('expenses.index', $q + ['format' => 'pdf']) }}" class="btn btn-secondary"><x-icon name="file-pdf" class="size-4 text-danger"/> PDF</a>
            @endcan
            @can('expenses.create')
                <a href="{{ route('expenses.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Xərc əlavə et') }}</a>
            @endcan
        </x-slot:actions>
    </x-page-header>
    @include('expenses._tabs')

    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6 stagger">
        <div class="card p-4" style="--i:0"><div class="text-xs text-muted">{{ __('Dövr üzrə xərc') }}</div><div class="text-xl font-semibold font-mono">{{ money($stats['total']) }}</div><div class="text-[11px] text-muted">{{ azdate($from) }} — {{ azdate($to) }} · AZN ekvivalenti</div></div>
        <div class="card p-4" style="--i:1"><div class="text-xs text-muted">{{ __('Ödənilib') }}</div><div class="text-xl font-semibold font-mono text-success">{{ money($stats['paid']) }}</div>
            <div class="text-[11px] text-muted">Hesabdan: {{ money($stats['bank']) }} · Nağd: {{ money($stats['cash']) }}</div></div>
        <div class="card p-4" style="--i:2"><div class="text-xs text-muted">{{ __('Ödənilməyib (dövr)') }}</div><div class="text-xl font-semibold font-mono text-saffron">{{ money($stats['unpaid']) }}</div>
            @if($stats['overdue'])<div class="text-[11px] text-danger">{{ $stats['overdue'] }} xərcin ödəniş tarixi keçib</div>@endif</div>
        <div class="card p-4" style="--i:3"><div class="text-xs text-muted">{{ __('Bütün ödənilməmiş xərclər') }}</div><div class="text-xl font-semibold font-mono">{{ money($unpaidAll) }}</div>
            <a href="{{ route('expenses.index', ['status' => 'unpaid', 'from' => '2000-01-01', 'to' => today()->addYears(5)->toDateString()]) }}" class="text-[11px] text-brand-ink hover:underline">{{ __('Hamısına bax') }}</a></div>
    </div>

    <div class="grid xl:grid-cols-[minmax(0,1fr)_320px] gap-6 items-start">
        <div class="space-y-4 min-w-0">
            <form method="GET" class="card p-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-3 items-end">
                <x-field :label="__('Başlanğıc')" name="from"><input type="date" name="from" value="{{ $from }}" class="input"></x-field>
                <x-field :label="__('Son')" name="to"><input type="date" name="to" value="{{ $to }}" class="input"></x-field>
                <x-field :label="__('Kateqoriya')" name="category_id">
                    <select name="category_id" class="input"><option value="">{{ __('Hamısı') }}</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(($filters['category_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach</select>
                </x-field>
                <x-field :label="__('Status')" name="status">
                    <select name="status" class="input"><option value="">{{ __('Hamısı') }}</option>@foreach(\App\Models\Expense::STATUSES as $k => [$l])<option value="{{ $k }}" @selected(($filters['status'] ?? null) === $k)>{{ $l }}</option>@endforeach</select>
                </x-field>
                <x-field :label="__('Ödəniş üsulu')" name="method">
                    <select name="method" class="input"><option value="">{{ __('Hamısı') }}</option>@foreach(\App\Models\Expense::METHODS as $k => $l)<option value="{{ $k }}" @selected(($filters['method'] ?? null) === $k)>{{ $l }}</option>@endforeach</select>
                </x-field>
                <div class="flex gap-2 sm:col-span-2 lg:col-span-3"><input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('Axtar…') }}" class="input flex-1 min-w-0" aria-label="{{ __('Axtar') }}"><button class="btn btn-primary btn-icon" aria-label="{{ __('Filtr') }}"><x-icon name="filter" class="size-4"/></button></div>
            </form>

            <section class="card overflow-hidden">
                @if($expenses->isEmpty())
                    <x-empty icon="receipt" :title="__('Bu dövrdə xərc yoxdur')" :text="__('Xərc əlavə edin və ya başqa dövr seçin.')">
                        @can('expenses.create')<a href="{{ route('expenses.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4"/> {{ __('Xərc əlavə et') }}</a>@endcan
                    </x-empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="table-g table-stack text-[13px]">
                            <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Xərc') }}</th><th>{{ __('Kateqoriya') }}</th><th class="!text-right">{{ __('Məbləğ') }}</th><th>{{ __('Status') }}</th><th>{{ __('Ödəniş') }}</th><th class="w-28"></th></tr></thead>
                            <tbody>
                            @foreach($expenses as $e)
                                <tr>
                                    <td data-label="Tarix" class="font-mono text-xs whitespace-nowrap">{{ azdate($e->expense_date) }}</td>
                                    <td data-label="Xərc" class="max-w-[300px]">
                                        <div class="font-medium text-ink line-clamp-2">{{ $e->description }}</div>
                                        <div class="text-[11px] text-muted">{{ $e->counterparty?->name }}@if($e->deal) · <a href="{{ route('deals.show', $e->deal) }}" class="hover:text-brand-ink">{{ $e->deal->code }}</a>@endif</div>
                                    </td>
                                    <td data-label="Kateqoriya">@if($e->category)<span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full shrink-0" style="background: {{ $e->category->color ?: '#94a3b8' }}"></span>{{ $e->category->name }}</span>@else<span class="text-faint">—</span>@endif</td>
                                    <td data-label="Məbləğ" class="num font-medium">{{ money($e->amount, $e->currency) }}@if($e->currency !== 'AZN' && $e->amount_azn !== null)<div class="text-[11px] text-faint">{{ money($e->amount_azn) }}</div>@endif</td>
                                    <td data-label="Status">
                                        @php [$sl, $st] = \App\Models\Expense::STATUSES[$e->status]; @endphp
                                        <span class="badge badge-{{ $e->isOverdue() ? 'rose' : $st }}">{{ $e->isOverdue() ? 'Gecikir' : $sl }}</span>
                                        @if(! $e->isPaid() && $e->due_date)<div class="text-[11px] text-muted mt-0.5">son: {{ azdate($e->due_date) }}</div>@endif
                                    </td>
                                    <td data-label="Ödəniş" class="text-xs">
                                        @if($e->isPaid())
                                            {{ \App\Models\Expense::METHODS[$e->payment_method] }} · {{ azdate($e->paid_at) }}
                                            @if($e->account)<div class="text-muted">{{ $e->account->name }}
                                                @if($e->bank_transaction_id)@can('bank.view')<a href="{{ route('bank.transactions.show', $e->bank_transaction_id) }}" class="text-brand-ink hover:underline">{{ __('· çıxarış') }}</a>@endcan @endif</div>@endif
                                        @else — @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        @can('expenses.update')
                                            @unless($e->isPaid())<a href="{{ route('expenses.edit', [$e, 'pay' => 1]) }}" class="btn btn-secondary btn-sm">{{ __('Ödə') }}</a>@endunless
                                            <a href="{{ route('expenses.edit', $e) }}" class="btn btn-ghost btn-icon btn-sm" aria-label="{{ __('Redaktə') }}"><x-icon name="pencil" class="size-4"/></a>
                                        @endcan
                                        @can('expenses.delete')
                                            <form method="POST" action="{{ route('expenses.destroy', $e) }}" class="inline" data-confirm="Xərc silinsin?{{ $e->bank_transaction_id ? ' Bank hesabından silinmə də ləğv olunacaq.' : '' }}" data-confirm-action="{{ __('Sil') }}">
                                                @csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm text-danger" aria-label="{{ __('Sil') }}"><x-icon name="trash" class="size-4"/></button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{ $expenses->links() }}
                @endif
            </section>
        </div>

        <aside class="card p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ __('Kateqoriyalar üzrə') }}</h2>
                <a href="{{ route('expenses.categories') }}" class="text-xs text-brand-ink hover:underline">{{ __('İdarə et') }}</a>
            </div>
            @php $max = max(1, (float) $byCategory->max()); $catNames = $categories->keyBy('id'); @endphp
            @forelse($byCategory as $cid => $sum)
                @php $cat = $catNames[$cid] ?? null; @endphp
                <a href="{{ route('expenses.index', $q + ['category_id' => $cid ?: null]) }}" class="block group">
                    <div class="flex items-baseline justify-between gap-2 text-sm">
                        <span class="truncate group-hover:text-brand-ink">{{ $cat?->name ?? 'Kateqoriyasız' }}</span>
                        <span class="font-mono text-xs">{{ money($sum) }}</span>
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-surface-2 overflow-hidden"><div class="h-full rounded-full" style="width: {{ round($sum / $max * 100) }}%; background: {{ $cat?->color ?: 'var(--color-brand)' }}"></div></div>
                </a>
            @empty
                <p class="text-sm text-muted">{{ __('Bu dövrdə xərc yoxdur.') }}</p>
            @endforelse
        </aside>
    </div>
</x-layouts.app>
