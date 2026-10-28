<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide CBAR history (not tenant data). */
class CurrencyRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_date' => 'date', 'bulletin_date' => 'date', 'rate' => 'decimal:8', 'value' => 'decimal:8', 'nominal' => 'decimal:2'];
    }

    /** Who entered a hand-made rate (null for CBAR's own). */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
