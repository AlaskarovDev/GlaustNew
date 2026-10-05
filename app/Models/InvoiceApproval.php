<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event of an invoice's approval: submitted, approved, rejected or withdrawn (append-only). */
class InvoiceApproval extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const ACTIONS = ['submitted' => ['təsdiqə göndərdi', 'blue'], 'approved' => ['təsdiqlədi', 'green'], 'rejected' => ['geri qaytardı', 'rose'], 'withdrawn' => ['geri çəkdi', 'slate'], 'locked' => ['təsdiqlədi və kilidlədi', 'green'], 'unlocked' => ['müvəqqəti kilidi açdı', 'amber']];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
