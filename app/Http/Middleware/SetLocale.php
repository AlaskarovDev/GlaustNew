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
        if ($locale !== 'az') {
            $this->translateConfigLabels();
        }

        return $next($request);
    }

    /** Labels kept in config/glaust.php (statuses, types, modules, reports) in the request's language. */
    private function translateConfigLabels(): void
    {
        $walk = function ($value) use (&$walk) {
            if (is_array($value)) {
                return array_map($walk, $value);
            }

            return is_string($value) && preg_match('/[^\x00-\x7F]|\s|^[A-Z]/', $value) ? __($value) : $value; // not codes / icon names
        };
        foreach (self::LABEL_GROUPS as $group) {
            config(["glaust.$group" => $walk(config("glaust.$group"))]);
        }
    }

    private const LABEL_GROUPS = ['modules', 'actions', 'statuses', 'counterparty_types', 'entity_types', 'contract_kinds',
        'shipment_directions', 'transport_modes', 'cost_types', 'analytics', 'transaction_kinds', 'reminder_sources'];
}
