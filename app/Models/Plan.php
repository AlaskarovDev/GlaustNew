<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'is_active' => 'boolean', 'monthly_price' => 'decimal:2'];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
