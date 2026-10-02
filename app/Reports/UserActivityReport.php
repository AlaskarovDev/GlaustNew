<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\LoginLog;
use App\Models\User;
use App\Tables\Column;
use Illuminate\Support\Collection;

class UserActivityReport extends Report
{
    private ?Collection $data = null;

    public static function key(): string
    {
        return 'user-activity';
    }

    public static function title(): string
    {
        return 'İstifadəçi fəaliyyəti';
    }

    public static function description(): string
    {
        return 'Hər istifadəçi üzrə girişlər, uğursuz cəhdlər, sessiya müddəti və sistemdə etdiyi dəyişikliklər.';
    }

    public static function icon(): string
    {
        return 'shield';
    }

    public static function ability(): string
    {
        return 'logs.view';
    }

    private function data(): Collection
    {
        if ($this->data) {
            return $this->data;
        }
        $range = [$this->from()->startOfDay(), $this->to()->endOfDay()];
        $logins = LoginLog::whereBetween('created_at', $range)->selectRaw('user_id, event, COUNT(*) as n, SUM(duration_seconds) as d')->groupBy('user_id', 'event')->get()->groupBy('user_id');
        $audits = AuditLog::whereBetween('created_at', $range)->selectRaw('user_id, action, COUNT(*) as n')->groupBy('user_id', 'action')->get()->groupBy('user_id');

        return $this->data = User::forTenant()->with('role')->orderBy('name')->get()->map(function (User $u) use ($logins, $audits) {
            $l = $logins[$u->id] ?? collect();
            $a = $audits[$u->id] ?? collect();
            $closed = $l->whereIn('event', ['logout', 'session_expired', 'forced_logout']);

            return [
                'name' => $u->name, 'email' => $u->email, 'role' => $u->role?->name, 'active' => $u->is_active ? 'Aktiv' : 'Deaktiv',
                'logins' => (int) $l->where('event', 'login')->sum('n'),
                'failed' => (int) $l->whereIn('event', ['failed', 'locked', 'two_factor_failed'])->sum('n'),
                'hours' => round((float) $closed->sum('d') / 3600, 1),
                'created' => (int) $a->where('action', 'created')->sum('n'),
                'updated' => (int) $a->where('action', 'updated')->sum('n'),
                'deleted' => (int) $a->where('action', 'deleted')->sum('n'),
                'last' => $u->last_login_at,
            ];
        });
    }

    public function columns(): array
    {
        return [
            Column::make('İstifadəçi', 'name'),
            Column::make('Email', 'email'),
            Column::make('Rol', 'role'),
            Column::make('Status', 'active'),
            Column::make('Giriş', 'logins', 'number', total: true),
            Column::make('Uğursuz cəhd', 'failed', 'number', total: true),
            Column::make('Sessiya (saat)', 'hours', 'number', total: true),
            Column::make('Yaratdı', 'created', 'number', total: true),
            Column::make('Dəyişdi', 'updated', 'number', total: true),
            Column::make('Sildi', 'deleted', 'number', total: true),
            Column::make('Son giriş', 'last', 'datetime'),
        ];
    }

    public function rows(): iterable
    {
        return $this->data();
    }

    public function summary(): array
    {
        $d = $this->data();

        return [
            ['label' => 'Girişlər', 'value' => $d->sum('logins')],
            ['label' => 'Uğursuz cəhdlər', 'value' => $d->sum('failed'), 'tone' => $d->sum('failed') ? 'danger' : null],
            ['label' => 'Dəyişikliklər', 'value' => $d->sum('created') + $d->sum('updated') + $d->sum('deleted')],
        ];
    }

    public function chart(): ?array
    {
        $d = $this->data()->filter(fn ($r) => $r['logins'] || $r['created'] || $r['updated']);
        if ($d->isEmpty()) {
            return null;
        }

        return ['type' => 'bar', 'height' => 280, 'stacked' => true, 'colors' => ['#0f9d8a', '#6366f1', '#e9a23b'], 'categories' => $d->pluck('name')->values()->all(),
            'series' => [['name' => 'Yaratdı', 'data' => $d->pluck('created')->values()->all()], ['name' => 'Dəyişdi', 'data' => $d->pluck('updated')->values()->all()], ['name' => 'Giriş', 'data' => $d->pluck('logins')->values()->all()]]];
    }
}
