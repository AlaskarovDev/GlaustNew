<?php

namespace App\Http\Controllers;

use App\Support\Exchanges\Exchanges;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** «Birja»: every currency against the US dollar at a market or a central bank the user picks, with a 30 / 90-day chart. */
class ExchangeRateController extends Controller
{
    private const PINNED = ['EUR', 'RUB', 'TRY', 'AZN', 'GBP', 'CNY', 'KZT', 'UAH', 'GEL', 'CHF', 'JPY'];

    public function index(Request $request): View
    {
        $source = Exchanges::get((string) $request->query('source', 'moex'));
        $latest = Exchanges::latest($source);
        $rates = $latest['rates'];
        uksort($rates, fn ($a, $b) => [array_search($a, self::PINNED, true) === false ? 99 : array_search($a, self::PINNED, true), $a]
            <=> [array_search($b, self::PINNED, true) === false ? 99 : array_search($b, self::PINNED, true), $b]);

        $code = strtoupper((string) $request->query('code', ''));
        if (! isset($rates[$code])) {
            $code = (string) (array_key_first($rates) ?? 'EUR');
        }
        $days = in_array((int) $request->query('days'), [30, 90, 365], true) ? (int) $request->query('days') : 30;
        [$history, $historyError] = $rates ? Exchanges::history($source, $code, $days) : [[], null];

        return view('exchanges.index', [
            'sources' => Exchanges::all(), 'source' => $source, 'date' => $latest['date'], 'rates' => $rates, 'error' => $latest['error'],
            'code' => $code, 'days' => $days, 'history' => $history, 'historyError' => $historyError,
        ]);
    }
}
