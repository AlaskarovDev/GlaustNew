<?php

use App\Models\Company;
use App\Services\AuthLogger;
use App\Services\Cbar\CurrencyRates;
use App\Services\Cbar\RateUnavailable;
use App\Services\NotificationMailer;
use App\Services\ReminderService;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
 * Every job iterates companies and runs inside Tenant::runAs(), because tenant
 * models are fail-closed: outside a tenant context they return nothing.
 */
$eachCompany = function (callable $fn): array {
    $out = [];
    foreach (Company::whereIn('subscription_status', ['trial', 'active'])->get() as $company) {
        if (! $company->isUsable()) {
            continue;
        }
        $out[$company->name] = app(Tenant::class)->runAs($company, fn () => $fn($company));
    }

    return $out;
};

Artisan::command('glaust:reminders {--send-only}', function () use ($eachCompany) {
    $lock = Cache::lock('glaust:reminders', 600);
    if (! $lock->get()) {
        $this->warn('Another run is in progress.');

        return;
    }
    try {
        $result = $eachCompany(function (Company $c) {
            $created = $this->option('send-only') ? 0 : app(ReminderService::class)->generate($c);
            $sent = app(NotificationMailer::class)->sendDueReminders($c);

            return "yaradıldı: {$created}, mail: {$sent}";
        });
        foreach ($result as $name => $line) {
            $this->line("{$name}: {$line}");
        }
    } finally {
        $lock->release();
    }
})->purpose('Generate reminders from business dates and email the due ones');

Artisan::command('glaust:digest {--force}', function () use ($eachCompany) {
    $lock = Cache::lock('glaust:digest', 900);
    if (! $lock->get()) {
        return;
    }
    try {
        foreach ($eachCompany(fn (Company $c) => app(NotificationMailer::class)->sendDigests($c, (bool) $this->option('force'))) as $name => $n) {
            $this->line("{$name}: {$n} xülasə");
        }
    } finally {
        $lock->release();
    }
})->purpose('Send the morning digest to every user who has not had it today');

Artisan::command('glaust:cbar-fetch {date?}', function (CurrencyRates $rates) {
    $date = $this->argument('date') ?? $rates->today()->format('Y-m-d');
    try {
        Cache::forget('cbar_rates_'.str_replace('-', '', $rates->normalize($date)->format('Y-m-d')));
        $list = $rates->ratesFor($date);
        $this->info(count($list)." valyuta yükləndi ({$date}). USD = ".($list['USD']['rate'] ?? '—'));
    } catch (RateUnavailable|\InvalidArgumentException $e) {
        $this->error($e->getMessage());

        return 1;
    }
})->purpose("Load today's (or the given date's) official CBAR rates");

Artisan::command('glaust:cbar-backfill {days=45}', function (CurrencyRates $rates) {
    $this->info($rates->backfill((int) $this->argument('days')).' gün yükləndi.');
})->purpose('Fill missing days of CBAR history');

Artisan::command('glaust:sweep-sessions', function (AuthLogger $log) {
    $this->info($log->sweepExpiredSessions().' sessiya bitmiş kimi qeyd edildi.');
})->purpose('Log session_expired for sessions that ended without a logout');

/* ---------- schedule (Asia/Baku) ---------- */

// A real cron marks itself, so the web-request fallback stays off (see RunScheduleFromWeb).
Schedule::call(function () {
    if (PHP_SAPI === 'cli') {
        Cache::forever('schedule:cron-seen', now()->getTimestamp());
    }
})->name('cron-heartbeat')->everyMinute();

Schedule::command('glaust:reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('glaust:digest')->everyTenMinutes()->between('07:00', '12:00')->withoutOverlapping(15);
Schedule::command('glaust:cbar-fetch')->hourlyAt(7)->between('08:00', '23:00');
Schedule::command('glaust:cbar-backfill 45')->dailyAt('03:20');
Schedule::command('glaust:sweep-sessions')->everyFifteenMinutes();
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5);
Schedule::command('model:prune')->daily();
