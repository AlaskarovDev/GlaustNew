<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety net for shared hosting without a configured cron.
 *
 * If the real cron (`schedule:run` every minute, which marks `schedule:cron-seen`) has
 * not been seen for 3 minutes, the periodic jobs run here, after the response has been
 * flushed (terminate() follows fastcgi_finish_request / litespeed_finish_request).
 * Jobs run IN-PROCESS via Artisan::call: `schedule:run` would spawn child PHP processes
 * through proc_open with PHP_BINARY, which shared hosts often disable (and under LSAPI
 * PHP_BINARY is not the CLI binary).
 */
class RunScheduleFromWeb
{
    /** command => [interval seconds, arguments] */
    private const JOBS = [
        'glaust:reminders' => [300, []],
        'glaust:digest' => [600, []],
        'glaust:cbar-fetch' => [3600, []],
        'glaust:sweep-sessions' => [900, []],
        'queue:work' => [60, ['--stop-when-empty' => true, '--max-time' => 40, '--tries' => 3]],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('glaust.web_scheduler', true) || app()->runningUnitTests()) {
            return;
        }

        $cronSeen = Cache::get('schedule:cron-seen');
        if ($cronSeen && now()->getTimestamp() - $cronSeen < 180) {
            return;
        }

        $lock = Cache::lock('schedule:web-run', 120);
        if (! $lock->get()) {
            return;
        }

        try {
            @set_time_limit(150);
            ignore_user_abort(true);
            foreach (self::JOBS as $command => [$every, $args]) {
                $key = 'webcron:'.$command;
                if (Cache::get($key, 0) > now()->getTimestamp() - $every) {
                    continue;
                }
                Cache::forever($key, now()->getTimestamp());
                try {
                    Artisan::call($command, $args);
                } catch (\Throwable $e) {
                    Log::error("Web scheduler: {$command} failed: ".$e->getMessage());
                }
            }
        } finally {
            $lock->release();
        }
    }
}
