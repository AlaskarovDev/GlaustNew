<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Users are NOT globally tenant-scoped: authentication must find them before a
 * tenant is known. Tenant code queries users through forTenant() or
 * $company->users(), never User::find() on a request-supplied id.
 */
class User extends Authenticatable
{
    use Auditable, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'position', 'role_id', 'is_active',
        'theme', 'locale', 'daily_digest', 'email_reminders', 'dashboard_layout',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'invitation_token'];

    /** Bookkeeping columns that change on every login; not worth an audit row. */
    public array $auditExclude = [
        'last_login_at', 'last_login_ip', 'failed_attempts', 'locked_until', 'last_digest_on',
        'dashboard_layout', 'theme',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'permission_overrides' => 'array',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'daily_digest' => 'boolean',
            'email_reminders' => 'boolean',
            'dashboard_layout' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'last_digest_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /** The users of the current company: its members, wherever they are working right now. */
    public function scopeForTenant(Builder $query): Builder
    {
        return $query->whereIn('id', CompanyUser::select('user_id')->where('company_id', app(Tenant::class)->id() ?? -1));
    }

    protected static function booted(): void
    {
        // users.role_id / permission_overrides are the user's in the company the user is in now: kept on that membership too
        static::saved(function (User $u) {
            if ($u->company_id) {
                CompanyUser::updateOrCreate(['company_id' => $u->company_id, 'user_id' => $u->id],
                    ['role_id' => $u->role_id, 'permission_overrides' => $u->permission_overrides]);
            }
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** The companies the user may switch to (active ones), by name. */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')->withPivot('role_id')->orderBy('name');
    }

    public function membershipIn(int $companyId): ?CompanyUser
    {
        return $this->memberships()->where('company_id', $companyId)->first();
    }

    /** The user's role in a company — the one in users.role_id while the user is in it, else the membership's. */
    public function roleIn(int $companyId): ?Role
    {
        return (int) $this->company_id === $companyId ? $this->role : $this->membershipIn($companyId)?->role;
    }

    /**
     * Sets the user's role (and, given, the permission overrides) in a company: on the membership, and on the user's own
     * columns too when the user is in that company now — never the role of another company onto the one the user is in.
     */
    public function setAccessIn(int $companyId, int $roleId, array|null|false $overrides = false): void
    {
        $m = CompanyUser::firstOrNew(['company_id' => $companyId, 'user_id' => $this->id]);
        $m->role_id = $roleId;
        if ($overrides !== false) {
            $m->permission_overrides = $overrides;
        }
        $m->save();
        if ((int) $this->company_id === $companyId) {
            $this->forceFill(['role_id' => $roleId] + ($overrides !== false ? ['permission_overrides' => $overrides] : []))->save();
        }
    }

    /** Moves the user into another company of theirs: its role and permission overrides become the user's. */
    public function switchTo(Company $company): void
    {
        $m = $this->membershipIn($company->id);
        abort_unless($m !== null, 403);
        $this->company_id = $company->id;
        $this->role_id = $m->role_id;
        $this->permission_overrides = $m->permission_overrides;
        $this->save();
        $this->unsetRelation('role')->unsetRelation('company');
    }

    /** The roles of these users in the current company (a user working elsewhere right now shows the role here). */
    public static function withRolesHere(iterable $users): void
    {
        $tid = app(Tenant::class)->id();
        foreach ($users as $u) {
            if ($tid && (int) $u->company_id !== $tid) {
                $u->setRelation('role', $u->membershipIn($tid)?->role);
            }
        }
    }

    public function isCompanyAdmin(): bool
    {
        return (bool) $this->role?->is_admin;
    }

    public function hasPermission(string $ability): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $module = explode('.', $ability, 2)[0];
        if ($this->company && ! $this->company->hasModule($module)) {
            return false;
        }

        // the user's own exceptions: a «deny» wins over the role, a «grant» adds to it (an admin is never narrowed)
        $o = $this->permission_overrides ?? [];
        if ($o && ! $this->role?->is_admin) {
            if (\App\Support\Permissions::denied($o['deny'] ?? [], $ability)) {
                return false;
            }
            if (\App\Support\Permissions::listed($o['grant'] ?? [], $ability)) {
                return true;
            }
        }

        return (bool) $this->role?->allows($ability);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /** Stable hue for avatar backgrounds. */
    public function avatarHue(): int
    {
        return crc32($this->email) % 360;
    }

    public function sendPasswordResetNotification($token): void
    {
        $url = route('password.reset', ['token' => $token, 'email' => $this->email]);

        app(\App\Services\MailService::class)->send($this->company, $this->email, new \App\Mail\SystemMail(
            mailSubject: 'Şifrənin yenilənməsi — TradeFlow',
            heading: 'Şifrənizi yeniləyin',
            lines: [
                'Salam, '.$this->name.'!',
                'Hesabınız üçün şifrə yeniləmə sorğusu aldıq. Link 60 dəqiqə etibarlıdır.',
                'Bu sorğunu siz göndərməmisinizsə, bu məktubu nəzərə almayın — şifrəniz dəyişməyəcək.',
            ],
            actionText: 'Şifrəni yenilə',
            actionUrl: $url,
            companyName: $this->company?->name,
        ), 'password_reset', $this->id);
    }

    public function auditLabel(): string
    {
        return $this->name.' <'.$this->email.'>';
    }
}
