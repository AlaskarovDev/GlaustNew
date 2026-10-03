<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Hesabatlar menu. The business rules of each report are to be given by the company; until then
 * each page shows what it will cover and which recorded data it will be built on.
 */
class AnalyticsController extends Controller
{
    public function show(string $report): View
    {
        $all = config('glaust.analytics');
        abort_unless(isset($all[$report]), 404);
        [$title, $icon, $description, $contents] = $all[$report];

        return view('analytics.show', compact('report', 'title', 'icon', 'description', 'contents', 'all'));
    }
}
