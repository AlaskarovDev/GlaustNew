<?php

namespace App\Services;

use App\Models\LoginLog;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Login / logout journal. Writes are explicit (called by the auth controllers
 * after the session id has been regenerated), so every login row carries the
 * real session id and its matching logout / expiry can close it with a duration.
 */
class AuthLogger
{
    public function __construct(private Tenant $tenant) {}

    public function login(User $user): void
    {
        $this->write($user, 'login', ['session_id' => session()->getId()]);
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
            'failed_attempts' => 0,
            'locked_until' => null,
        ])->saveQuietly();
    }

    public function failed(string $email, ?User $user, string $event = 'failed'): void
    {
        $this->write($user, $event, ['email' => Str::lower($email)]);
    }

    /** Closes the user's open login row for the current session. */
    public function logout(User $user, string $event = 'logout'): void
    {
        $sessionId = session()->getId();
        $this->closeLogins($user, $sessionId, now()->getTimestamp());
        $this->write($user, $event, ['session_id' => $sessionId]);
    }

    public function passwordChanged(User $user, string $event = 'password_changed'): void
    {
        $this->write($user, $event);
    }

    /**
     * Signs a user out everywhere: deletes their database sessions and rotates the
     * remember token so "remember me" cookies stop working too.
     */
    public function forceLogoutEverywhere(User $user, ?string $exceptSessionId = null): int
    {
        $query = DB::table('sessions')->where('user_id', $user->id);
        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }
        $ids = $query->pluck('id');
        DB::table('sessions')->whereIn('id', $ids)->delete();

        if (! $exceptSessionId) {
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        }

        foreach ($ids as $id) {
            $this->closeLogins($user, $id, now()->getTimestamp());
        }
        $this->write($user, 'forced_logout');

        return $ids->count();
    }

    /**
     * Records `session_expired` for login rows whose session is gone or idle past the
     * lifetime. Duration = last activity - login time. Run from the scheduler.
     */
    public function sweepExpiredSessions(): int
    {
        $lifetime = (int) config('session.lifetime') * 60;
        $count = 0;

        LoginLog::withoutGlobalScopes()
            ->where('event', 'login')->where('closed', false)
            ->where('created_at', '<', now()->subMinutes(5))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($lifetime, &$count) {
                $sessions = DB::table('sessions')->whereIn('id', $rows->pluck('session_id')->filter())
                    ->pluck('last_activity', 'id');

                foreach ($rows as $row) {
                    $last = $sessions[$row->session_id] ?? null;
                    if ($last !== null && $last > now()->getTimestamp() - $lifetime) {
                        continue; // still alive
                    }
                    $end = $last ?? $row->created_at->getTimestamp();
                    $row->forceFill([
                        'closed' => true,
                        'duration_seconds' => max(0, $end - $row->created_at->getTimestamp()),
                    ])->save();

                    LoginLog::withoutGlobalScopes()->create([
                        'company_id' => $row->company_id, 'user_id' => $row->user_id, 'email' => $row->email,
                        'event' => 'session_expired', 'ip_address' => $row->ip_address, 'user_agent' => $row->user_agent,
                        'device' => $row->device, 'session_id' => $row->session_id,
                        'duration_seconds' => max(0, $end - $row->created_at->getTimestamp()),
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function closeLogins(User $user, ?string $sessionId, int $endTs): void
    {
        if (! $sessionId) {
            return;
        }
        LoginLog::withoutGlobalScopes()
            ->where('user_id', $user->id)->where('session_id', $sessionId)
            ->where('event', 'login')->where('closed', false)
            ->get()
            ->each(fn (LoginLog $row) => $row->forceFill([
                'closed' => true,
                'duration_seconds' => max(0, $endTs - $row->created_at->getTimestamp()),
            ])->save());
    }

    private function write(?User $user, string $event, array $extra = []): void
    {
        $agent = (string) request()->userAgent();

        LoginLog::withoutGlobalScopes()->create(array_merge([
            'company_id' => $user?->company_id ?? $this->tenant->id(),
            'user_id' => $user?->id,
            'email' => $user?->email,
            'event' => $event,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr($agent, 0, 500),
            'device' => self::describeAgent($agent),
        ], $extra));
    }

    /** "Chrome · Windows" from a user agent string. */
    public static function describeAgent(?string $agent): string
    {
        $agent = (string) $agent;
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'YaBrowser') => 'Yandex',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Brauzer',
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('naməlum OS'),
        };

        return $browser.' · '.$os;
    }
}
