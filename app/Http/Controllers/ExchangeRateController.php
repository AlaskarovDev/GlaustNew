<?php

namespace App\Http\Controllers;

use App\Support\Exchanges\Exchanges;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** «Birja»: every currency against the US dollar at a market or a central bank the user picks, with a 30 / 90-day chart. */
class ExchangeRateController extends Controller
{
    private const PINNED = ['EUR', 'RUB', 'TRY', 'AZN', 'GBP', 'CNY', 'KZT', 'UAH', 'GEL', 'CHF', 'JPY'];

    /** The free widgets of sites with no open data: shown as they come, in a sandboxed frame. */
    public static function widgets(): array
    {
        return [
            'profinance' => ['name' => 'ProFinance.ru', 'country' => 'Rusiya', 'site' => 'https://www.profinance.ru/informers/'],
            'investing' => ['name' => 'Investing.com', 'country' => __('Beynəlxalq'), 'site' => 'https://www.investing.com/webmaster-tools/live-currency-cross-rates'],
        ];
    }

    public function index(Request $request): View
    {
        $widget = (string) $request->query('source');
        if (isset(self::widgets()[$widget])) {
            return view('exchanges.index', ['sources' => Exchanges::all(), 'widgets' => self::widgets(), 'widget' => $widget,
                'investingSrc' => tenant()->setting('widgets.investing')]);
        }
        $source = Exchanges::get((string) $request->query('source', 'cbr'));
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

        // today against the last day before it in the history
        $before = array_filter($history, fn ($d) => $d < ($latest['date'] ?? '9999'), ARRAY_FILTER_USE_KEY);
        $prevDay = $before ? array_key_last($before) : null;
        $now = $rates[$code] ?? null;
        $change = $prevDay && $now && $before[$prevDay] > 0
            ? ['abs' => $now - $before[$prevDay], 'pct' => ($now - $before[$prevDay]) / $before[$prevDay] * 100, 'day' => $prevDay] : null;

        return view('exchanges.index', [
            'widgets' => self::widgets(), 'widget' => null,
            'change' => $change, 'tiles' => array_slice($rates, 0, 6, true),
            'sources' => Exchanges::all(), 'source' => $source, 'date' => $latest['date'], 'rates' => $rates, 'error' => $latest['error'],
            'code' => $code, 'days' => $days, 'history' => $history, 'historyError' => $historyError,
        ]);
    }

    /**
     * The Investing.com widget: the code its webmaster tools generate (after its terms are accepted there), pasted by an
     * admin — only an https iframe of investing.com is kept, and only its address.
     */
    public function investing(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()->isCompanyAdmin(), 403);
        $code = trim((string) $request->input('code'));
        if ($code === '') {
            $c = tenant();
            $c->putSetting('widgets.investing', null);
            $c->save();

            return back()->with('success', __('Investing.com vidjeti silindi.'));
        }
        preg_match('/\bsrc\s*=\s*["\']?(https:\/\/[^"\'\s>]+)/i', $code, $m);
        $src = $m[1] ?? (str_starts_with($code, 'https://') ? $code : null);
        $host = $src ? strtolower((string) parse_url(html_entity_decode($src), PHP_URL_HOST)) : '';
        if (! $src || ! ($host === 'investing.com' || str_ends_with($host, '.investing.com'))) {
            return back()->with('error', __('Investing.com-un vidjet kodu tanınmadı: «Generate HTML Code» ilə alınan iframe kodunu yapışdırın.'));
        }
        $c = tenant();
        $c->putSetting('widgets.investing', html_entity_decode($src));
        $c->save();

        return back()->with('success', __('Investing.com vidjeti qoşuldu.'));
    }
}
