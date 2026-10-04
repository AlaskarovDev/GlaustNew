<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface language: the signed-in user's choice, else the one picked on the login page (session),
 * else the application default (az). Texts are keyed by their Azerbaijani original (lang/ru.json,
 * lang/en.json); anything not translated yet falls back to Azerbaijani.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('glaust.locales'));
        $locale = $request->user()?->locale ?: $request->session()->get('locale');
        if (! in_array($locale, $available, true)) {
            $locale = config('app.locale');
        }
        app()->setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }
}
