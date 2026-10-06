<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Support\FxDifference;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Müsbət və mənfi məzənnə fərqi: operations (date, amount, currency → CBAR rate of that day), the project's end date,
 * then the calculation per the Tax Code. Every rate is CBAR's; a future end date takes a forecast rate the user enters.
 */
class FxDifferenceController extends Controller
{
    public function index(Request $request, CurrencyRates $rates): View
    {
        $projects = Project::orderByDesc('start_date')->orderByDesc('id')->get(['id', 'code', 'name', 'end_date']);
        $view = ['projects' => $projects, 'currencies' => array_values(array_diff(config('glaust.currencies'), ['AZN'])), 'result' => null];
        if (! $request->has('lines')) {
            return view('fx-difference.index', $view);
        }

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', \App\Rules\TenantExists::in('projects')],
            'end_date' => ['required', 'date'],
            'forecast' => ['array'],
            'forecast.*' => ['nullable', 'numeric', 'gt:0'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.case' => ['required', Rule::in(FxDifference::CASES)],
            'lines.*.currency' => ['required', Rule::in($view['currencies'])],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.date' => ['required', 'date', 'before_or_equal:end_date', 'before_or_equal:today'],
            'lines.*.note' => ['nullable', 'string', 'max:120'],
            'lines.*.nonres' => ['nullable', 'boolean'],
        ], [
            'lines.*.date.before_or_equal' => __('Əməliyyat tarixi bitmə tarixindən və bu gündən gec ola bilməz.'),
        ]);

        $end = $rates->normalize(substr($data['end_date'], 0, 10));
        $future = $end->gt($rates->today());
        $rows = [];
        $errors = [];
        $warnings = [];
        foreach ($data['lines'] as $i => $l) {
            $cur = $l['currency'];
            $date1 = substr($l['date'], 0, 10);
            try {
                $rate1 = $rates->rate($cur, $date1);
                if ($future) {
                    $rate2 = (float) ($data['forecast'][$cur] ?? 0) ?: throw new RateUnavailable(__(':cur üçün :date tarixinə CBAR məzənnəsi hələ yoxdur — proqnoz məzənnəni daxil edin.', ['cur' => $cur, 'date' => azdate($end)]));
                } else {
                    $rate2 = $rates->rate($cur, $end);
                }
                $yearEnd = [];
                if (in_array($l['case'], ['alis_borc', 'satis_borc'], true)) {
                    for ($y = (int) substr($date1, 0, 4); $y < $end->year; $y++) {
                        $r = "{$y}-12-31" <= $rates->today()->toDateString() ? $rates->tryRate($cur, "{$y}-12-31") : null;
                        if ($r) {
                            $yearEnd[$y] = $r;
                        } else {
                            $warnings[] = __('Sətir :n: :date üçün məzənnə yoxdur — il sonu yenidən qiymətləndirmə buraxıldı.', ['n' => $i + 1, 'date' => "31.12.{$y}"]);
                        }
                    }
                }
            } catch (RateUnavailable|\InvalidArgumentException $e) {
                $errors[] = __('Sətir :n', ['n' => $i + 1]).': '.$e->getMessage();
                continue;
            }
            $rows[] = $l + ['note' => null, 'nonres' => false, 'n' => $i + 1, 'date' => $date1, 'rate1' => $rate1, 'rate2' => $rate2, 'labels' => FxDifference::dateLabels($l['case']),
                'calc' => FxDifference::calc($l['case'], (float) $l['amount'], $date1, $rate1, $end->toDateString(), $rate2, $yearEnd, (bool) ($l['nonres'] ?? false))];
        }

        $years = [];
        foreach ($rows as $r) {
            foreach ($r['calc']['steps'] as $s) {
                if ($s['kind'] !== 'none') {
                    $years[$s['year']][$s['kind']] = round(($years[$s['year']][$s['kind']] ?? 0) + $s['amount'], 2);
                }
            }
        }
        ksort($years);

        return view('fx-difference.index', [...$view,
            'result' => [
                'rows' => $rows, 'errors' => $errors, 'warnings' => array_values(array_unique($warnings)), 'future' => $future, 'end' => $end,
                'project' => isset($data['project_id']) ? $projects->firstWhere('id', $data['project_id']) : null,
                'years' => $years,
                'positive' => round(collect($rows)->sum(fn ($r) => $r['calc']['positive']), 2),
                'negative' => round(collect($rows)->sum(fn ($r) => $r['calc']['negative']), 2),
            ],
        ]);
    }
}
