<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Balans qalığı (1C): a balance line's figure at a day, from the company's books; the latest on or before a day stands. */
class BalanceEntry extends Model
{
    use Auditable, BelongsToCompany;

    /** The lines that can be given so (the 1C accounts the system does not keep), code => label. */
    public const LINES = ['68' => 'Büdcə ilə hesablaşmalar', '69' => 'Sosial sığorta və təminat üzrə hesablaşmalar', '70' => 'Əməyin ödənişi üzrə işçi heyəti ilə hesablaşmalar',
        '75' => 'Təsisçilərlə hesablaşmalar', '76' => 'Müxtəlif D və K hesablaşmalar', '85' => 'Nizamnamə kapitalı', '87' => 'Əlavə kapital'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function auditLabel(): string
    {
        return 'Balans qalığı '.$this->line.': '.$this->description;
    }
}
