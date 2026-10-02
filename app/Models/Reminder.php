<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime', 'snoozed_until' => 'datetime',
            'read_at' => 'datetime', 'emailed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Unread, due by the end of today, and not snoozed into the future. */
    public function scopeActiveFor(Builder $q, User $user): Builder
    {
        return $q->where('user_id', $user->id)
            ->whereNull('read_at')
            ->where('remind_at', '<=', now()->endOfDay())
            ->where(fn ($w) => $w->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    public function isOverdue(): bool
    {
        return $this->remind_at->lt(today());
    }

    public function sourceLabel(): string
    {
        return config('glaust.reminder_sources.'.$this->source, $this->source);
    }
}
