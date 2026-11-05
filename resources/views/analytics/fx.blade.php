<x-layouts.app :title="$title" wide>
    <x-page-header :title="$title" :icon="$icon" :subtitle="$description">
        <x-slot:actions>
            <a href="{{ route('analytics.show', ['report' => 'fx', 'from' => $from, 'to' => $to, 'format' => 'xlsx']) }}" class="btn btn-secondary"><x-icon name="sheet" class="size-4 text-success"/> Excel</a>
        </x-slot:actions>
    </x-page-header>

    @include('analytics._bar')

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
