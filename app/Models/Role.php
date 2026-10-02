<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_admin' => 'boolean', 'is_system' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** "projects.view" matches "projects.view", "projects.*" or "*". */
    public function allows(string $ability): bool
    {
        if ($this->is_admin) {
            return true;
        }
        $module = explode('.', $ability, 2)[0];
        $granted = $this->permissions ?? [];

        return in_array('*', $granted, true)
            || in_array($ability, $granted, true)
            || in_array($module.'.*', $granted, true);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
