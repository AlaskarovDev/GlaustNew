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

        return view('analytics.show', compact('report', 'title', 'icon', 'description', 'contents', 'all'));
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
        $rows = $report->rows($deals, $asOf);
        $columns = TradeReport::columns();
        $groups = TradeReport::groups();

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
            ]);

            return app(SpreadsheetExporter::class)->download(__('Yekun hesabat'), $cols, $rows, 'yekun-hesabat-'.now()->format('Y-m-d').'.xlsx', array_values($filters));
        }

        // for the formula window: every row's values, formatted
        $cells = array_map(fn ($r) => [
            'label' => $r['seller_no'], 'trade' => $r['deal']->code,
            'v' => collect($columns)->keys()->mapWithKeys(fn ($k) => [$k => $fmt($r, $k)])->all(),
        ], $rows);

        return view('analytics.summary', $page + [
            'projects' => $projects, 'dealList' => $dealList, 'projectId' => $projectId, 'dealId' => $dealId, 'asOf' => $asOf,
            'month' => $asOf ? substr($asOf, 0, 7) : $month, 'lastMonth' => $lastMonth, 'monthError' => $monthError,
            'rows' => $rows, 'columns' => $columns, 'groups' => $groups, 'totals' => TradeReport::totals($rows), 'fmt' => $fmt, 'cells' => $cells,
        ]);
    }
}
