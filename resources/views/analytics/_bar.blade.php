{{-- Above every report: the reports, and the one period they all follow (kept in the session; see AnalyticsController::sharedPeriod). Expects $all, $report. --}}
@php
    $per = session('analytics.period');
    $keepQ = collect(request()->query())->except(['from', 'to', 'month', 'format', 'calc', 'as_month'])->filter(fn ($v) => is_scalar($v) && $v !== '')->all();
    $now = today();
    $ym = fn ($d) => $d->format('Y-m');
    $quickP = [
        __('Bu ay') => [$ym($now), $ym($now)],
        __('Keçən ay') => [$ym($now->copy()->subMonthNoOverflow()), $ym($now->copy()->subMonthNoOverflow())],
        __('Bu rüb') => [$ym($now->copy()->firstOfQuarter()), $ym($now)],
        __('Bu il') => [$now->format('Y').'-01', $ym($now)],
        __('Keçən il') => [($now->year - 1).'-01', ($now->year - 1).'-12'],
        __('Son 12 ay') => [$ym($now->copy()->subMonthsNoOverflow(11)), $ym($now)],
    ];
    $periodLabel = fn ($m) => \Carbon\Carbon::createFromFormat('!Y-m', $m)->locale(app()->getLocale())->isoFormat('MMMM YYYY');
@endphp
<nav class="flex gap-2 overflow-x-auto pb-1 mb-4 -mx-1 px-1 no-scrollbar" aria-label="{{ __('Hesabatlar') }}">
    @foreach($all as $key => [$navLabel, $ico])
        <a href="{{ route('analytics.show', $key) }}" @class(['badge !h-9 !px-3.5 !text-[13px] gap-1.5 shrink-0', 'badge-teal' => $key === $report, 'badge-slate hover:!text-ink' => $key !== $report]) @if($key === $report) aria-current="page" @endif>
            <x-icon :name="$ico" class="size-4"/> {{ __($navLabel) }}
        </a>
    @endforeach
</nav>

<form method="GET" action="{{ route('analytics.show', $report) }}" class="card p-4 mb-6 flex flex-wrap items-end gap-3 border-brand/30">
    <div class="w-full sm:w-auto">
        <div class="text-sm font-semibold flex items-center gap-1.5"><x-icon name="calendar" class="size-4 text-brand"/> {{ __('Dövr') }}</div>
        <div class="text-[11px] text-muted">{{ $per ? $periodLabel($per['from']).' — '.$periodLabel($per['to']) : __('hər hesabatın öz dövrü') }} · {{ __('bütün hesabatlara tətbiq olunur') }}</div>
    </div>
    <label><span class="field-label">{{ __('Ayından') }}</span><input type="month" name="from" value="{{ $per['from'] ?? '' }}" class="input font-mono"></label>
    <label><span class="field-label">{{ __('Ayınadək') }}</span><input type="month" name="to" value="{{ $per['to'] ?? '' }}" class="input font-mono"></label>
    @foreach($keepQ as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <button class="btn btn-primary"><x-icon name="filter" class="size-4"/> {{ __('Göstər') }}</button>
    <div class="flex flex-wrap gap-1 lg:ml-auto">
        @foreach($quickP as $ql => [$qf, $qt])
            @php $on = $per && $per['from'] === $qf && $per['to'] === $qt; @endphp
            <a href="{{ route('analytics.show', [$report] + $keepQ + ['from' => $qf, 'to' => $qt]) }}" @class(['btn btn-sm', 'btn-ghost' => ! $on, 'bg-brand-soft text-brand-ink' => $on])>{{ $ql }}</a>
        @endforeach
    </div>
</form>
