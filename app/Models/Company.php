<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class Company extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'smtp_password' => 'encrypted',
            'trial_ends_at' => 'date',
            'subscription_ends_at' => 'date',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** Company setting with config('glaust.company_defaults') fallback. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, Arr::get(config('glaust.company_defaults'), $key, $default));
    }

    public function putSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        Arr::set($settings, $key, $value);
        $this->settings = $settings;
    }

    public function hasModule(string $module): bool
    {
        if (! in_array($module, config('glaust.plan_modules'), true)) {
            return true; // core modules are always available
        }

        return in_array($module, $this->plan?->modules ?? [], true);
    }

    public function isUsable(): bool
    {
        return match ($this->subscription_status) {
            'active' => ! $this->subscription_ends_at || $this->subscription_ends_at->copy()->endOfDay()->isFuture(),
            'trial' => ! $this->trial_ends_at || $this->trial_ends_at->copy()->endOfDay()->isFuture(),
            default => false,
        };
    }

    public function hasOwnSmtp(): bool
    {
        return filled($this->smtp_host) && filled($this->smtp_from_address);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
