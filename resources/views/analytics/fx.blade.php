<x-layouts.app :title="$title" wide>
    <x-page-header :title="$title" :icon="$icon" :subtitle="$description">
        <x-slot:actions>
            <a href="{{ route('analytics.show', ['report' => 'fx', 'from' => $from, 'to' => $to, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
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
        $now = today();
        $quick = [
            __('Bu il') => [$now->format('Y').'-01', $now->format('Y-m')],
            __('Keçən il') => [($now->year - 1).'-01', ($now->year - 1).'-12'],
        ];
        $months = [];
        for ($m = \Carbon\Carbon::createFromFormat('!Y-m', $from); $m->format('Y-m') <= $to; $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }
        $sign = fn ($v) => abs($v) < 0.005 ? '—' : ($v < 0 ? '−' : '+').num(abs($v));
    @endphp

    <form method="GET" action="{{ route('analytics.show', 'fx') }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3">
        <label><span class="field-label">{{ __('Ayından') }}</span><input type="month" name="from" value="{{ $from }}" class="input font-mono"></label>
        <label><span class="field-label">{{ __('Ayınadək') }}</span><input type="month" name="to" value="{{ $to }}" class="input font-mono"></label>
        <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
        <div class="flex flex-wrap gap-1 lg:ml-auto">
            @foreach($quick as $ql => [$qf, $qt])
                <a href="{{ route('analytics.show', ['fx', 'from' => $qf, 'to' => $qt]) }}" @class(['btn btn-sm', 'btn-ghost' => ! ($qf === $from && $qt === $to), 'bg-brand-soft text-brand-ink' => $qf === $from && $qt === $to])>{{ $ql }}</a>
            @endforeach
        </div>
    </form>

    @if($fx['rows'])
        <section class="card overflow-hidden mb-6">
            <header class="px-5 py-4 border-b border-line">
                <h2 class="text-base font-semibold">{{ __('Aylar üzrə') }}</h2>
                <p class="text-xs text-muted">{{ __('Xalis nəticə, AZN: + gəlir (bank CBAR-dan sərfəli), − xərc') }}</p>
            </header>
            <div class="overflow-x-auto">
                <table class="table-g text-sm">
                    <thead><tr>@foreach($months as $m)<th class="!text-right whitespace-nowrap">{{ $label($m) }}</th>@endforeach<th class="!text-right">{{ __('Cəmi') }}</th></tr></thead>
                    <tbody><tr>
                        @foreach($months as $m)
                            @php $v = $fx['months'][$m] ?? 0; @endphp
                            <td @class(['num whitespace-nowrap', 'text-success' => $v > 0, 'text-danger' => $v < 0])>{{ $sign($v) }}</td>
                        @endforeach
                        <td @class(['num font-semibold whitespace-nowrap', 'text-success' => $fx['net'] > 0, 'text-danger' => $fx['net'] < 0])>{{ $sign($fx['net']) }}</td>
                    </tr></tbody>
                </table>
            </div>
        </section>
    @endif

    @include('partials.fx-results', ['fx' => $fx, 'showTrade' => true])
</x-layouts.app>
