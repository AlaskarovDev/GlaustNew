<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One line of an uploaded bank statement, as the bank shows it. */
class BankAuditLine extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['line_date' => 'date', 'amount' => 'decimal:2', 'amount_azn' => 'decimal:2', 'balance' => 'decimal:2'];
    }
}
