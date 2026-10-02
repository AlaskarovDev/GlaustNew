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
        'theme', 'daily_digest', 'email_reminders', 'dashboard_layout',
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

    public function scopeForTenant(Builder $query): Builder
    {
        return $query->where('company_id', app(Tenant::class)->id() ?? -1);
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
            mailSubject: 'Şifrənin yenilənməsi — Glaust MS',
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
