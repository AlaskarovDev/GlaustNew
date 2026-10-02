<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Child rows: always reached through a tenant-scoped Counterparty. */
class CounterpartyContact extends Model
{
    protected $guarded = ['id'];

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }
}
