<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LoginLog;
use App\Models\MailLog;
use App\Models\User;
use App\Services\AuthLogger;
use App\Support\Export\PdfExporter;
use App\Support\Export\SpreadsheetExporter;
use App\Tables\Column;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class LogController extends Controller
{
    public const EVENTS = [
        'login' => 'Giriş', 'logout' => 'Çıxış', 'failed' => 'Uğursuz cəhd', 'locked' => 'Hesab bağlandı',
        'password_changed' => 'Şifrə dəyişdi', 'password_reset' => 'Şifrə bərpa edildi', 'session_expired' => 'Sessiya bitdi',
        'forced_logout' => 'Məcburi çıxış', 'two_factor_failed' => '2FA kodu səhv',
    ];

    public function logins(Request $request): View|Response
    {
        $query = LoginLog::with('user')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->query('event')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('email', 'like', '%'.$request->query('q').'%')->orWhere('ip_address', 'like', '%'.$request->query('q').'%')))
            ->latest('created_at')->latest('id');

        if ($format = $request->query('format')) {
            $this->authorize('logs.export');
            $cols = [
                Column::make('Tarix', 'created_at', 'datetime'), Column::make('İstifadəçi', fn ($l) => $l->user?->name),
                Column::make('Email', 'email'), Column::make('Hadisə', fn ($l) => self::EVENTS[$l->event] ?? $l->event),
                Column::make('IP', 'ip_address'), Column::make('Cihaz', 'device'),
                Column::make('Müddət (dəq)', fn ($l) => $l->duration_seconds !== null ? round($l->duration_seconds / 60, 1) : null, 'number'),
            ];
            $name = 'giris-loglari-'.now()->format('Y-m-d');

            return $format === 'pdf'
                ? app(PdfExporter::class)->download('Giriş-çıxış logları', $cols, $query->limit(3000)->get(), $name.'.pdf')
                : app(SpreadsheetExporter::class)->download('Giriş-çıxış logları', $cols, $query->lazy(), $name.'.xlsx');
        }

        $stats = LoginLog::where('created_at', '>=', now()->subDays(7))->selectRaw('event, COUNT(*) as n')->groupBy('event')->pluck('n', 'event');

        return view('settings.logs.logins', [
            'logs' => $query->paginate(50)->withQueryString(),
            'users' => User::forTenant()->orderBy('name')->pluck('name', 'id'),
            'stats' => $stats,
        ]);
    }

    public function sessions(): View
    {
        $lifetime = (int) config('session.lifetime') * 60;
        $sessions = DB::table('sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('users.company_id', tenant()->id)
            ->where('sessions.last_activity', '>=', now()->getTimestamp() - $lifetime)
            ->orderByDesc('sessions.last_activity')
            ->get(['sessions.id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.id as user_id', 'users.name', 'users.email'])
            ->map(fn ($s) => (object) [
                'id' => $s->id, 'ip' => $s->ip_address, 'device' => AuthLogger::describeAgent($s->user_agent),
                'last' => \Carbon\Carbon::createFromTimestamp($s->last_activity)->setTimezone(config('app.timezone')),
                'user_id' => $s->user_id, 'name' => $s->name, 'email' => $s->email,
                'current' => $s->id === session()->getId(),
            ]);

        return view('settings.logs.sessions', compact('sessions'));
    }

    public function destroySession(Request $request, string $session, AuthLogger $log): RedirectResponse
    {
        $row = DB::table('sessions')->join('users', 'users.id', '=', 'sessions.user_id')
            ->where('users.company_id', tenant()->id)->where('sessions.id', $session)->first(['sessions.id', 'users.id as user_id']);
        abort_unless($row, 404);
        abort_if($row->id === $request->session()->getId(), 422, 'Cari sessiyanı buradan bağlamaq olmaz — çıxışdan istifadə edin.');

        DB::table('sessions')->where('id', $row->id)->delete();
        $user = User::forTenant()->findOrFail($row->user_id);
        LoginLog::where('session_id', $row->id)->where('event', 'login')->where('closed', false)->get()
            ->each(fn ($l) => $l->forceFill(['closed' => true, 'duration_seconds' => now()->getTimestamp() - $l->created_at->getTimestamp()])->save());
        LoginLog::create(['user_id' => $user->id, 'email' => $user->email, 'event' => 'forced_logout', 'ip_address' => $request->ip(),
            'device' => 'Admin: '.$request->user()->name, 'session_id' => $row->id]);

        return back()->with('success', 'Sessiya bağlandı.');
    }

    public function audit(Request $request): View|Response
    {
        $query = AuditLog::with('user')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('auditable_type', $request->query('type')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('q'), fn ($q) => $q->where('label', 'like', '%'.$request->query('q').'%'))
            ->latest('created_at')->latest('id');

        if ($format = $request->query('format')) {
            $this->authorize('logs.export');
            $cols = [
                Column::make('Tarix', 'created_at', 'datetime'), Column::make('İstifadəçi', fn ($a) => $a->user?->name ?? 'Sistem'),
                Column::make('Əməliyyat', fn ($a) => self::actions()[$a->action] ?? $a->action), Column::make('Obyekt', fn ($a) => self::types()[$a->auditable_type] ?? $a->auditable_type),
                Column::make('Qeyd', 'label', width: 34), Column::make('Dəyişikliklər', fn ($a) => self::diff($a), width: 60), Column::make('IP', 'ip_address'),
            ];
            $name = 'audit-'.now()->format('Y-m-d');

            return $format === 'pdf'
                ? app(PdfExporter::class)->download('Audit jurnalı', $cols, $query->limit(2000)->get(), $name.'.pdf')
                : app(SpreadsheetExporter::class)->download('Audit jurnalı', $cols, $query->lazy(), $name.'.xlsx');
        }

        return view('settings.logs.audit', [
            'logs' => $query->paginate(40)->withQueryString(),
            'users' => User::forTenant()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function mail(Request $request): View
    {
        $logs = MailLog::when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->query('kind')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('to', 'like', '%'.$request->query('q').'%')->orWhere('subject', 'like', '%'.$request->query('q').'%')))
            ->latest('created_at')->latest('id')->paginate(40)->withQueryString();

        return view('settings.logs.mail', compact('logs'));
    }

    public static function types(): array
    {
        return ['project' => 'Layihə', 'task' => 'Tapşırıq', 'contract' => 'Müqavilə', 'counterparty' => 'Kontragent', 'bank_transaction' => 'Bank əməliyyatı',
            'bank_account' => 'Bank hesabı', 'shipment' => 'Yük', 'shipment_cost' => 'Logistika xərci', 'user' => 'İstifadəçi', 'role' => 'Rol', 'company' => 'Şirkət'];
    }

    public static function actions(): array
    {
        return ['created' => 'Yaratdı', 'updated' => 'Dəyişdi', 'deleted' => 'Sildi', 'restored' => 'Bərpa etdi'];
    }

    public static function diff(AuditLog $a): string
    {
        if ($a->action !== 'updated') {
            return '';
        }
        $parts = [];
        foreach ((array) $a->new_values as $k => $v) {
            $old = $a->old_values[$k] ?? null;
            $parts[] = $k.': '.(is_array($old) ? json_encode($old, JSON_UNESCAPED_UNICODE) : (string) $old).' → '.(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v);
        }

        return implode('; ', $parts);
    }
}
