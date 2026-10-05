<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Project;
use App\Support\Export\SpreadsheetExporter;
use App\Support\Profit\ProfitCalculator;
use App\Tables\Column;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Hesabatlar → Mənfəətin hesablanması: every project, its Trades and their shipments, step by step. */
class ProfitController extends Controller
{
    public function __construct(private ProfitCalculator $calc) {}

    public function index(): View
    {
        $projects = Project::whereHas('deals')->orderByDesc('start_date')->orderByDesc('id')->get()
            ->map(fn (Project $p) => $this->calc->project($p));

        return view('profit.index', ['projects' => $projects, 'totals' => $this->calc->totals(
            $projects->flatMap(fn ($p) => collect($p['deals'])->flatMap(fn ($d) => $d['rows']))->all()
        )]);
    }

    public function project(Project $project): View
    {
        return view('profit.project', $this->calc->project($project));
    }

    public function deal(Deal $deal): View
    {
        $deal->loadMissing('project', 'counterparty', 'supplier');

        return view('profit.deal', $this->calc->deal($deal));
    }

    /** The project's shipments as one sheet, column by column as in the company's workbook. */
    public function export(Project $project): StreamedResponse
    {
        $data = $this->calc->project($project);
        $rows = [];
        foreach ($data['deals'] as $d) {
            foreach ($d['rows'] as $r) {
                $rows[] = ['deal' => $d['deal']] + $r;
            }
        }
        $v = fn (string $stage, string $key) => fn ($r) => isset($r[$stage][$key]) ? round($r[$stage][$key], 2) : null;
        $cols = [
            Column::make('Trade', fn ($r) => $r['deal']->code),
            Column::make(__('Satıcı fakturası'), fn ($r) => $r['invoice']->number),
            Column::make('D · '.__('Satıcıya (valyuta)'), fn ($r) => $r['D'], 'money', total: true),
            Column::make(__('Proforma'), fn ($r) => $r['sale']?->number),
            Column::make('H · '.__('Alıcıdan (valyuta)'), fn ($r) => $r['H'], 'money', total: true),
            Column::make('P · '.__('Alış, proqnoz'), $v('forecast', 'P'), 'money', total: true),
            Column::make('Q · '.__('Satış, proqnoz'), $v('forecast', 'Q'), 'money', total: true),
            Column::make('R · '.__('Logistika, proqnoz'), $v('forecast', 'R'), 'money', total: true),
            Column::make('S · '.__('Köçürmə komissiyası, proqnoz'), $v('forecast', 'S'), 'money', total: true),
            Column::make(__('Proqnoz mənfəət'), $v('forecast', 'profit'), 'money', total: true),
            Column::make(__('Akt tarixi'), fn ($r) => $r['act']['date'] ?? null, 'date'),
            Column::make('BL · '.__('Alış, akt tarixinə'), $v('act', 'BL'), 'money', total: true),
            Column::make('BM · '.__('Satış, akt tarixinə'), $v('act', 'BM'), 'money', total: true),
            Column::make('BO · '.__('Logistika, akt tarixinə'), $v('act', 'BO'), 'money', total: true),
            Column::make('BP · '.__('Akt tarixinə mənfəət'), $v('act', 'BP'), 'money', total: true),
            Column::make(__('Əməliyyat tarixi'), fn ($r) => $r['settle']['date'] ?? null, 'date'),
            Column::make('AB · '.__('Alış, ödəniş tarixinə'), $v('settle', 'AB'), 'money', total: true),
            Column::make('AC · '.__('Satış, ödəniş tarixinə'), $v('settle', 'AC'), 'money', total: true),
            Column::make('BQ · '.__('Satış üzrə kurs fərqi'), $v('settle', 'BQ'), 'money', total: true),
            Column::make('BR · '.__('Alış üzrə kurs fərqi'), $v('settle', 'BR'), 'money', total: true),
            Column::make('BS · '.__('Logistika kurs fərqi'), $v('settle', 'BS'), 'money', total: true),
            Column::make('AJ · '.__('Köçürmə komissiyası'), $v('settle', 'AJ'), 'money', total: true),
            Column::make('BB · '.__('Logistika komissiyası'), $v('settle', 'BB'), 'money', total: true),
            Column::make('BT · '.__('Xalis mənfəət (CBAR)'), $v('settle', 'BT'), 'money', total: true),
            Column::make(__('Bank kursu ilə fərq'), $v('bank', 'total'), 'money', total: true),
            Column::make(__('Digər xərclər'), $v('bank', 'expenses'), 'money', total: true),
            Column::make('BE · '.__('Yekun mənfəət'), $v('bank', 'final'), 'money', total: true),
            Column::make(__('Qeyd'), fn ($r) => $r['estimated'] ? __('təxmini: ödənilməmiş hissə bugünkü CBAR kursu ilə') : null),
        ];

        return app(SpreadsheetExporter::class)->download(__('Mənfəətin hesablanması').' — '.$project->code, $cols, $rows,
            'menfeet-'.$project->code.'.xlsx', [$project->name, __('Məbləğlər AZN-dədir (D və H — öz valyutasında).')]);
    }
}
