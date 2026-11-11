<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Balans qalığı (1C), from the company's books:
 *   LINES — the accounts the system does not keep (41, 68, 75, 85 …): the figure at a day; the latest on or before a day stands
 *           (several entries of that day add up);
 *   FIXES — the lines the system makes (60, 61, 62, 64, 88): a 1C correction on that day only (what 1C has and the data has not,
 *           e.g. a receivable 1C keeps that was booked as an expense), added to the line's own items.
 */
class BalanceEntry extends Model
{
    use Auditable, BelongsToCompany;

    /** The lines that can be given so (the 1C accounts the system does not keep), code => label. */
    public const LINES = ['68' => 'Büdcə ilə hesablaşmalar', '69' => 'Sosial sığorta və təminat üzrə hesablaşmalar', '70' => 'Əməyin ödənişi üzrə işçi heyəti ilə hesablaşmalar',
        '75' => 'Təsisçilərlə hesablaşmalar', '76' => 'Müxtəlif D və K hesablaşmalar', '85' => 'Nizamnamə kapitalı', '87' => 'Əlavə kapital', '41' => 'Mallar'];

    /** The computed lines a 1C correction can be given for (on its day only). */
    public const FIXES = ['60' => 'Malsatan və podratçılarla hesablaşmalar', '61' => 'Verilmiş avanslar üzrə hesablaşmalar', '62' => 'Alıcılar və sifarişçilərlə hesablaşmalar',
        '64' => 'Alınmış avanslar üzrə hesablaşmalar', '88' => 'Bölüşdürülməmiş mənfəət (ödənilməmiş zərər)'];

    /** The lines of the AKTİVLƏR side; the rest are PASSİVLƏR. */
    public const ASSETS = ['41', '61', '62'];

    public static function known(string $line): bool
    {
        return isset(self::LINES[$line]) || isset(self::FIXES[$line]);
    }

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
