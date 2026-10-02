<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MailLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public bool $tenantOptional = true;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
