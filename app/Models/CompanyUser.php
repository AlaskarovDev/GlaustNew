<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's membership of a company: the role and permission overrides the user has there. */
class CompanyUser extends Model
{
    protected $table = 'company_user';

    protected $fillable = ['company_id', 'user_id', 'role_id', 'permission_overrides'];

    protected function casts(): array
    {
        return ['permission_overrides' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }
}
