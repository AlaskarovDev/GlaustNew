<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Project;
use App\Support\Export\SpreadsheetExporter;
use App\Support\Reports\TradeReport;
use App\Tables\Column;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hesabatlar menu. «Yekun hesabat» is the company's ATF workbook built from recorded data; the other
 * pages show what they will cover until their rules are agreed.
 */
class AnalyticsController extends Controller
{
    public function show(Request $request, string $report): View|Response
    {
        $all = config('glaust.analytics');
        abort_unless(isset($all[$report]), 404);
        [$title, $icon, $description, $contents] = $all[$report];

        if ($report === 'summary') {
            return $this->summary($request, compact('report', 'title', 'icon', 'description', 'all'));
        }
        if ($report === 'cashflow') {
            return $this->cashflow($request, compact('report', 'title', 'icon', 'description', 'all'));
        }

        return view('analytics.show', compact('report', 'title', 'icon', 'description', 'contents', 'all'));
    }

    /** Cash flow by month (AZN): money in per counterparty, money out per counterparty / expense, fees and exchange results. */
    private function cashflow(Request $request, array $page): View|Response
    {
        $projectId = $request->integer('project_id') ?: null;
        $report = new \App\Support\Reports\CashflowReport($projectId);
        [$first, $last] = $report->span();
        $valid = fn ($m) => is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : null;
        $now = today()->format('Y-m');
        $to = $valid($request->query('to')) ?? max($last ?? $now, $now);
        $from = $valid($request->query('from')) ?? max($first ?? $to, \Carbon\Carbon::createFromFormat('!Y-m', $to)->subMonthsNoOverflow(11)->format('Y-m'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (\Carbon\Carbon::createFromFormat('!Y-m', $from)->diffInMonths(\Carbon\Carbon::createFromFormat('!Y-m', $to)) > 35) {
            $from = \Carbon\Carbon::createFromFormat('!Y-m', $to)->subMonthsNoOverflow(35)->format('Y-m');   // at most 36 columns
        }
        $data = $report->build($from, $to);
        $label = fn ($m) => \Carbon\Carbon::createFromFormat('!Y-m', $m)->locale(app()->getLocale())->isoFormat('MMM YY');

        if ($request->query('format') === 'xlsx') {
            $line = fn ($name, $values, $total = null) => ['name' => $name] + $values + ['total' => $total ?? array_sum($values)];
            $rows = [$line(__('Dövrün əvvəlinə qalıq'), $data['opening'], reset($data['opening']) ?: 0), ['name' => __('GƏLİRLƏR')]];
            foreach ($data['income'] as $g) {
                $rows[] = $line($g['label'], $g['values'], $g['total']);
            }
            $rows[] = $line(__('Cəmi gəlirlər'), $data['in']);
            $rows[] = ['name' => __('ÖDƏNİŞLƏR')];
            foreach ($data['payments'] as $g) {
                $rows[] = $line($g['label'], $g['values'], $g['total']);
            }
            $rows[] = $line(__('Cəmi ödənişlər'), $data['out']);
            $rows[] = $line(__('Xalis pul axını'), $data['net']);
            $rows[] = $line(__('Dövrün sonuna qalıq'), $data['closing'], end($data['closing']) ?: 0);
            $cols = [Column::make('CASH FLOW', 'name', 'text', 34)];
            foreach ($data['months'] as $m) {
                $cols[] = Column::make($label($m), fn ($r) => $r[$m] ?? null, 'money');
            }
            $cols[] = Column::make(__('Cəmi'), 'total', 'money');
            $filters = array_filter([__('Dövr').': '.$label($from).' — '.$label($to), $projectId ? __('Layihə').': '.Project::find($projectId)?->name : null]);

            return app(SpreadsheetExporter::class)->download('Cash flow', $cols, $rows, 'cash-flow-'.$from.'-'.$to.'.xlsx', array_values($filters));
        }

        return view('analytics.cashflow', $page + ['data' => $data, 'from' => $from, 'to' => $to, 'projectId' => $projectId, 'label' => $label,
            'projects' => Project::whereHas('deals')->orderByDesc('start_date')->orderByDesc('id')->get(['id', 'code', 'name'])]);
    }

    /** Yekun hesabat: one row per seller invoice of the chosen project / Trade, every ATF column computed. */
    private function summary(Request $request, array $page): View|Response
    {
        $projects = Project::whereHas('deals')->orderByDesc('start_date')->orderByDesc('id')->get(['id', 'code', 'name']);
        $dealList = Deal::with('counterparty:id,name', 'supplier:id,name')->orderByDesc('deal_date')->orderByDesc('id')->get(['id', 'project_id', 'code', 'title', 'counterparty_id', 'supplier_id']);
        $projectId = $request->integer('project_id') ?: null;
        $dealId = $request->integer('deal_id') ?: null;
        // month-end valuation of unfinished Trades: the last 12 month ends up to today
        // month-end valuation of unfinished Trades: the user picks a month (Y-m), the system takes its last day
        $lastMonth = today()->endOfMonth()->lte(today()) ? today()->format('Y-m') : today()->subMonthNoOverflow()->format('Y-m');   // the latest month already over
        $month = (string) $request->query('as_month', '');
        if ($month === '' && $request->boolean('calc')) {
            $month = $lastMonth;   // «Ay sonuna görə hesabla» without a month: the last finished one
        }
        $asOf = null;
        $monthError = null;
        if ($month !== '') {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $monthError = __('Ayı seçin (ay və il).');
            } elseif ($month > $lastMonth) {
                $monthError = __(':v1 hələ bitməyib — ay sonunun CBAR kursu yoxdur. Bitmiş ay seçin.', ['v1' => $month]);
            } else {
                $asOf = \Carbon\Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString();
            }
        }

        $deals = Deal::query()
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($dealId, fn ($q) => $q->whereKey($dealId))
            ->with('project:id,code,name')->get();
        $report = app(TradeReport::class);
        // «1C metodu ilə məzənnə fərqi»: extra columns at the chosen month's end (the last finished month by default)
        $oneC = $request->boolean('c1') ? ($asOf ?? \Carbon\Carbon::createFromFormat('!Y-m', $lastMonth)->endOfMonth()->toDateString()) : null;
        $rows = $report->rows($deals, $asOf, $oneC);
        $columns = TradeReport::columns();
        $groups = TradeReport::groups();
        if (! $oneC) {
            $columns = array_filter($columns, fn ($c) => $c['g'] !== 'onec');
            unset($groups['onec']);
        }

        $fmt = function (array $r, string $key) use ($columns): string {
            $v = $r[$key] ?? null;
            if ($v === null || $v === '') {
                return '—';
            }
            $t = $columns[$key]['t'];

            return match (true) {
                $t === 'date' => azdate($v),
                $t === 'rate' => rate_fmt(round((float) $v, 6)),
                $t === 'azn' => money((float) $v),
                str_starts_with($t, 'cur:') => money((float) $v, $r[substr($t, 4)] ?? null),
                default => (string) $v,
            };
        };

        if ($request->query('format') === 'xlsx') {
            $cols = [];
            foreach ($columns as $key => $c) {
                $type = match (true) { $c['t'] === 'date' => 'date', $c['t'] === 'rate' => 'rate', $c['t'] === 'azn', str_starts_with($c['t'], 'cur:') => 'money', default => 'text' };
                $label = $groups[$c['g']][0].' · '.$c['l'].(str_starts_with($c['t'], 'cur:') ? '' : ($c['t'] === 'azn' ? ' (AZN)' : ''));
                $cols[] = Column::make($label, fn ($r) => $r[$key] ?? null, $type, total: $c['t'] === 'azn');
            }
            $filters = array_filter([
                $projectId ? __('Layihə').': '.($projects->firstWhere('id', $projectId)?->name ?? '') : null,
                $dealId ? 'Trade: '.($dealList->firstWhere('id', $dealId)?->code ?? '') : null,
                $asOf ? __('Bitməyən Trade-lər :v1 (ay sonu) kursu ilə', ['v1' => azdate($asOf)]) : null,
                $oneC ? __('1C metodu: :v1 ay sonuna', ['v1' => azdate($oneC)]) : null,
            ]);

            return app(SpreadsheetExporter::class)->download(__('Yekun hesabat'), $cols, $rows, 'yekun-hesabat-'.now()->format('Y-m-d').'.xlsx', array_values($filters));
        }

        // for the formula window: every row's values, formatted, and the day each rate is taken at
        $rateDay = fn (array $ds) => match (count($ds)) {
            0 => null,
            1 => azdate($ds[0]),
            default => __('orta').': '.azdate($ds[0]).' – '.azdate(end($ds)).' ('.count($ds).')',
        };
        $cells = array_map(fn ($r) => [
            'label' => $r['seller_no'], 'trade' => $r['deal']->code,
            'v' => collect($columns)->keys()->mapWithKeys(fn ($k) => [$k => $fmt($r, $k)])->all(),
            'd' => array_filter(array_map($rateDay, $r['_rd'] ?? [])),
        ], $rows);

        return view('analytics.summary', $page + [
            'projects' => $projects, 'dealList' => $dealList, 'projectId' => $projectId, 'dealId' => $dealId, 'asOf' => $asOf,
            'month' => $asOf ? substr($asOf, 0, 7) : $month, 'lastMonth' => $lastMonth, 'monthError' => $monthError, 'oneC' => $oneC,
            'rows' => $rows, 'columns' => $columns, 'groups' => $groups, 'totals' => TradeReport::totals($rows), 'fmt' => $fmt, 'cells' => $cells,
        ]);
    }
}
