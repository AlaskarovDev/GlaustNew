<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The bank's fee rule for a currency from a date on (Tənzimləmələr → Bank komissiyası), for one bank (bank_name of the
 * paying account) or for every bank (bank_name NULL). A bank's own rule wins over the rule for every bank; the system
 * rule (config glaust.bank_fees) holds from the beginning, until a rule of the company replaces it.
 */
class BankFeeRule extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'percent' => 'float', 'minimum' => 'float', 'maximum' => 'float'];
    }

    protected static function booted(): void
    {
        $forget = fn () => app()->forgetInstance(self::key());
        static::saved($forget);
        static::deleted($forget);
    }

    private static function key(): string
    {
        return 'bank_fee_rules.'.(app(Tenant::class)->id() ?? 0);
    }

    /** How a bank's name is matched: case and spaces do not count. */
    public static function bankKey(?string $bank): ?string
    {
        $k = $bank === null ? '' : mb_strtolower(preg_replace('/\s+/u', ' ', trim($bank)));

        return $k === '' ? null : $k;
    }

    /**
     * Every rule, oldest first: [['bank' => null|key, 'currency', 'from' => null|'Y-m-d', 'percent', 'minimum', 'maximum'], ...].
     * from null = the system rule, in force before any rule of the company; bank null = for every bank.
     *
     * @return list<array{bank: ?string, currency: string, from: ?string, percent: float, minimum: float, maximum: ?float}>
     */
    public static function timeline(): array
    {
        $key = self::key();
        if (app()->bound($key)) {
            return app($key);
        }
        $out = [];
        foreach (config('glaust.bank_fees', []) as $cur => $r) {
            $out[] = ['bank' => null, 'currency' => $cur, 'from' => null, 'percent' => (float) $r['percent'], 'minimum' => (float) $r['minimum'],
                'maximum' => isset($r['maximum']) ? (float) $r['maximum'] : null];
        }
        $rows = app(Tenant::class)->id() ? self::query()->orderBy('valid_from')->orderBy('id')->get() : collect();
        foreach ($rows as $r) {
            $out[] = ['bank' => self::bankKey($r->bank_name), 'currency' => $r->currency, 'from' => $r->valid_from->toDateString(),
                'percent' => $r->percent, 'minimum' => $r->minimum, 'maximum' => $r->maximum];
        }
        app()->instance($key, $out);

        return $out;
    }

    /**
     * The rule of a currency in force on a date (today when null) for a bank: the bank's own rule, else the rule for
     * every bank; null when the currency has none yet.
     */
    public static function on(string $currency, \DateTimeInterface|string|null $date = null, ?string $bank = null): ?array
    {
        $day = $date === null ? today()->toDateString() : substr($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date, 0, 10);
        $bank = self::bankKey($bank);
        $own = $all = null;
        foreach (self::timeline() as $r) {
            if ($r['currency'] !== $currency || ($r['from'] !== null && $r['from'] > $day)) {
                continue;
            }
            if ($r['bank'] === null) {
                $all = $r;
            } elseif ($bank !== null && $r['bank'] === $bank) {
                $own = $r;
            }
        }

        return $own ?? $all;
    }
}
