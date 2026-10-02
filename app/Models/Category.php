<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
