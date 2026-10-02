<?php

namespace App\Http\Controllers;

use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The old valyuta.php, rebuilt: any date, quick links, full table, 30/90-day chart. */
class CurrencyController extends Controller
{
    public function index(Request $request, CurrencyRates $rates): View
    {
        $today = $rates->today();
        $error = null;

        try {
            $date = $request->filled('date') ? $rates->normalize((string) $request->query('date')) : $today;
            if ($date->gt($today)) {
                $error = 'Gələcək tarix üçün məzənnə dərc olunmur. Bugünkü məzənnələr göstərilir.';
                $date = $today;
            }
        } catch (\InvalidArgumentException) {
            $error = 'Tarix düzgün deyil. Bugünkü məzənnələr göstərilir.';
            $date = $today;
        }

        $list = [];
        $bulletin = null;
        try {
            $snap = $rates->snapshot($date);
            [$list, $bulletin] = [$snap['rates'], $snap['bulletin']];
        } catch (RateUnavailable $e) {
            $error = $e->getMessage();
        }

        // Compare with the bulletin before the one shown (Monday vs Friday, not vs Sunday).
        $previous = [];
        try {
            $previous = $rates->ratesFor(($bulletin ? $rates->normalize($bulletin) : $date)->subDay());
        } catch (RateUnavailable) {
        }

        $code = strtoupper((string) $request->query('code', 'USD'));
        $days = in_array((int) $request->query('days'), [30, 90, 365], true) ? (int) $request->query('days') : 30;
        $history = $rates->history($code, $days);

        $pinned = ['USD', 'EUR', 'RUB', 'TRY', 'GBP', 'GEL'];
        uksort($list, fn ($a, $b) => [array_search($a, $pinned) === false ? 99 : array_search($a, $pinned), $a]
            <=> [array_search($b, $pinned) === false ? 99 : array_search($b, $pinned), $b]);

        return view('currency.index', [
            'date' => $date,
            'bulletin' => $bulletin ? $rates->normalize($bulletin) : null,
            'today' => $today,
            'list' => $list,
            'previous' => $previous,
            'error' => $error,
            'code' => $code,
            'days' => $days,
            'history' => $history,
            'sourceUrl' => sprintf(config('glaust.cbar.url'), $date->format('d.m.Y')),
        ]);
    }
}
