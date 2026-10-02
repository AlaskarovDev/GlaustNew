<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAttachments;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use Auditable, BelongsToCompany, HasAttachments;

    protected $guarded = ['id', 'company_id'];

    public array $auditExclude = ['position'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime',
            'estimated_hours' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            if ($task->isDirty('status')) {
                $task->completed_at = $task->status === 'done' ? ($task->completed_at ?? now()) : null;
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->latest();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->latest('work_date');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '!=', 'done');
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->status !== 'done' && $this->due_date->lt(today());
    }

    public function auditLabel(): string
    {
        return $this->title;
    }
}
