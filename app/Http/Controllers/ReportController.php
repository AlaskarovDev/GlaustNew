<?php

namespace App\Http\Controllers;

use App\Reports;
use App\Reports\Report;
use App\Support\Export\PdfExporter;
use App\Support\Export\SpreadsheetExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    /** @var class-string<Report>[] */
    public const REPORTS = [
        Reports\IncomeExpenseReport::class,
        Reports\BankStatementReport::class,
        Reports\CounterpartyReport::class,
        Reports\ContractsReport::class,
        Reports\ProjectBudgetReport::class,
        Reports\LogisticsReport::class,
        Reports\ExchangeReport::class,
        Reports\UserActivityReport::class,
    ];

    public function index(Request $request): View
    {
        $reports = collect(self::REPORTS)->filter(fn ($r) => $request->user()->can($r::ability()));

        return view('reports.index', compact('reports'));
    }

    public function show(Request $request, string $report): View
    {
        $r = $this->resolve($request, $report);

        return view('reports.show', ['report' => $r, 'class' => $r::class, 'rows' => collect($r->rows())]);
    }

    public function export(Request $request, string $report): Response
    {
        $r = $this->resolve($request, $report);
        $name = Str::slug(strtr($r::title(), ['ə' => 'e', 'Ə' => 'E'])).'-'.now()->format('Y-m-d');
        $filters = $r->filterSummary();
        if ($note = $r->note()) {
            $filters[] = $note;
        }

        return $request->query('format') === 'pdf'
            ? app(PdfExporter::class)->download($r::title(), $r->columns(), $r->rows(), $name.'.pdf', $filters)
            : app(SpreadsheetExporter::class)->download($r::title(), $r->columns(), $r->rows(), $name.'.xlsx', $filters);
    }

    private function resolve(Request $request, string $key): Report
    {
        $class = collect(self::REPORTS)->first(fn ($r) => $r::key() === $key) ?? abort(404);
        $this->authorize($class::ability());

        return new $class($request);
    }
}
