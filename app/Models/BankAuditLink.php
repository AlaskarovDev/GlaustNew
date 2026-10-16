<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A member (a statement line or a system movement) of a group tied together by hand in the bank audit. */
class BankAuditLink extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];
}
