<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** MZ düzəlişi: ± AZN on a P&L line at a day (ProfitLossReport::lines() code); on an «in» line a minus lowers the income. */
class PlAdjustment extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['adjustment_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function auditLabel(): string
    {
        return 'MZ düzəlişi '.$this->line.': '.$this->description;
    }
}
