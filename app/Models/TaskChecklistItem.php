<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reached only through a tenant-scoped Task. */
class TaskChecklistItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_done' => 'boolean'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
