<?php

namespace App\Services\Cbar;

use App\Models\CurrencyRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Official AZN exchange rates (AZN per ONE unit), same rules as the old Glaust:
 *  - cache: past dates 30 days, today/yesterday 1 hour (CBAR may still publish/revise them);
 *  - history persisted in currency_rates (DECIMAL(18,8)) with the bulletin's own date;
 *  - an unreadable or future (Asia/Baku) date never reaches CBAR — CBAR would answer
 *    a future date with the latest bulletin, silently;
 *  - no fallback rates: a missing rate is an error (RateUnavailable).
 *
 * A "snapshot" is ['bulletin' => Y-m-d|null, 'rates' => [CODE => [rate, nominal, value, name]]].
 */
class CurrencyRates
{
    public function __construct(private CbarClient $client) {}

    /** @throws RateUnavailable */
    public function ratesFor(\DateTimeInterface|string $date): array
    {
        return $this->snapshot($date)['rates'];
    }

    /**
     * Rates in force on $date, with the date of the CBAR bulletin they come from.
     *
     * @throws RateUnavailable
     */
    public function snapshot(\DateTimeInterface|string $date): array
    {
        $day = $this->normalize($date);
        $key = $day->format('Y-m-d');

        if ($day->gt($this->today())) {
            throw RateUnavailable::for('—', $key, 'Gələcək tarix üçün məzənnə dərc olunmur.');
        }

        $recent = $day->gte($this->today()->subDay());

        return Cache::remember('cbar_rates_'.$day->format('Ymd'),
            $recent ? config('glaust.cbar.recent_ttl') : config('glaust.cbar.past_ttl'),
            function () use ($day, $key, $recent) {
                $stored = $this->fromDatabase($key);
                if ($stored['rates'] && ! $recent) {
                    return $stored; // published history never changes
                }

                // A recent failure is remembered for 5 minutes so an outage at CBAR
                // does not cost every page a 10-second timeout.
                $failKey = 'cbar_fail_'.$day->format('Ymd');
                $fetched = Cache::has($failKey) ? ['date' => null, 'rates' => []] : $this->client->fetch($day);
                if ($fetched['rates']) {
                    $this->store($key, $fetched['date'], $fetched['rates']);

                    return ['bulletin' => $fetched['date'], 'rates' => $fetched['rates']];
                }
                Cache::put($failKey, true, 300);

                // Rows already stored for this exact date are real CBAR rates, not a substitute.
                return $stored['rates'] ? $stored : throw RateUnavailable::for('—', $key, 'CBAR əlçatan deyil.');
            });
    }

    /** AZN per one unit. @throws RateUnavailable */
    public function rate(string $currency, \DateTimeInterface|string $date): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'AZN') {
            return 1.0;
        }

        $day = $this->normalize($date);
        $rates = $this->ratesFor($day);

        return $rates[$currency]['rate']
            ?? throw RateUnavailable::for($currency, $day->format('Y-m-d'));
    }

    public function tryRate(string $currency, \DateTimeInterface|string $date): ?float
    {
        try {
            return $this->rate($currency, $date);
        } catch (RateUnavailable|\InvalidArgumentException) {
            return null;
        }
    }

    /** amount (currency) -> AZN, rounded to qəpik. @throws RateUnavailable */
    public function toAzn(float $amount, string $currency, \DateTimeInterface|string $date): float
    {
        return round($amount * $this->rate($currency, $date), 2);
    }

    /**
     * Header ticker: the rates in force today, labelled with their bulletin date, and the
     * change against the previous bulletin. When nothing can be loaded, the latest stored
     * list is returned with stale=true and its own date.
     */
    public function ticker(array $codes): array
    {
        $today = $this->today();
        $stale = false;

        try {
            $snap = $this->snapshot($today);
        } catch (RateUnavailable) {
            $last = CurrencyRate::where('rate_date', '<=', $today->format('Y-m-d'))->max('rate_date');
            if (! $last) {
                return ['ok' => false, 'date' => null, 'stale' => true, 'items' => []];
            }
            $snap = $this->fromDatabase(CarbonImmutable::parse($last)->format('Y-m-d'));
            $snap['bulletin'] ??= CarbonImmutable::parse($last)->format('Y-m-d');
            $stale = true;
        }

        $bulletin = CarbonImmutable::parse($snap['bulletin'] ?? $today->format('Y-m-d'), 'Asia/Baku')->startOfDay();
        $previous = $this->previousRates($bulletin);
        $items = [];
        foreach ($codes as $code) {
            if (! isset($snap['rates'][$code])) {
                continue;
            }
            $now = $snap['rates'][$code]['rate'];
            $before = $previous[$code]['rate'] ?? null;
            $change = $before ? round(($now - $before) / $before * 100, 2) : null;
            $items[] = [
                'code' => $code,
                'name' => $snap['rates'][$code]['name'],
                'rate' => $now,
                'nominal' => $snap['rates'][$code]['nominal'],
                'value' => $snap['rates'][$code]['value'],
                'previous' => $before,
                'change' => $change,
                'trend' => $change === null || abs($change) < 0.005 ? 'flat' : ($change > 0 ? 'up' : 'down'),
            ];
        }

        return ['ok' => true, 'date' => $bulletin->format('Y-m-d'), 'stale' => $stale, 'items' => $items];
    }

    /** @return array<string, float> bulletin Y-m-d => rate, from stored history (oldest first). */
    public function history(string $currency, int $days = 30): array
    {
        $from = $this->today()->subDays($days - 1)->format('Y-m-d');

        // One point per published bulletin (weekends repeat Friday's bulletin).
        return CurrencyRate::where('currency_code', strtoupper($currency))
            ->where('rate_date', '>=', $from)
            ->where(fn ($q) => $q->whereColumn('rate_date', 'bulletin_date')->orWhereNull('bulletin_date'))
            ->orderBy('rate_date')
            ->get(['rate_date', 'rate'])
            ->mapWithKeys(fn ($r) => [$r->rate_date->format('Y-m-d') => (float) $r->rate])
            ->all();
    }

    /** Fill missing days of history; returns the number of days fetched. */
    public function backfill(int $days): int
    {
        $have = CurrencyRate::where('rate_date', '>=', $this->today()->subDays($days)->format('Y-m-d'))
            ->distinct()->pluck('rate_date')->map(fn ($d) => $d->format('Y-m-d'))->flip();

        $fetched = 0;
        for ($i = $days; $i >= 0; $i--) {
            $day = $this->today()->subDays($i);
            if ($i > 1 && isset($have[$day->format('Y-m-d')])) {
                continue;
            }
            try {
                Cache::forget('cbar_rates_'.$day->format('Ymd'));
                $this->snapshot($day);
                $fetched++;
            } catch (RateUnavailable) {
                // logged by the client; keep going with the other days
            }
        }

        return $fetched;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Baku')->startOfDay();
    }

    /** @throws \InvalidArgumentException for unreadable dates (never ask CBAR for 01.01.1970) */
    public function normalize(\DateTimeInterface|string $date): CarbonImmutable
    {
        if ($date instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($date->format('Y-m-d'), 'Asia/Baku')->startOfDay();
        }

        $date = trim($date);
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $date, new \DateTimeZone('Asia/Baku'));
            if ($parsed && $parsed->format($format) === $date) {
                return CarbonImmutable::instance($parsed);
            }
        }

        throw new \InvalidArgumentException("Tarix oxunmadı: {$date}");
    }

    /** Rates of the bulletin before $bulletin (Monday compares with Friday). */
    private function previousRates(CarbonImmutable $bulletin): array
    {
        try {
            return $this->ratesFor($bulletin->subDay());
        } catch (RateUnavailable) {
            $prev = CurrencyRate::where('rate_date', '<', $bulletin->format('Y-m-d'))->max('rate_date');

            return $prev ? $this->fromDatabase(CarbonImmutable::parse($prev)->format('Y-m-d'))['rates'] : [];
        }
    }

    private function fromDatabase(string $date): array
    {
        $rows = CurrencyRate::where('rate_date', $date)->get();

        return [
            'bulletin' => $rows->first()?->bulletin_date?->format('Y-m-d'),
            'rates' => $rows->mapWithKeys(fn (CurrencyRate $r) => [$r->currency_code => [
                'rate' => (float) $r->rate,
                'nominal' => (float) $r->nominal,
                'value' => (float) $r->value,
                'name' => (string) $r->name,
            ]])->all(),
        ];
    }

    private function store(string $date, ?string $bulletin, array $rates): void
    {
        $now = now();
        $rows = [];
        foreach ($rates as $code => $r) {
            $rows[] = [
                'currency_code' => $code, 'rate_date' => $date, 'bulletin_date' => $bulletin, 'rate' => $r['rate'],
                'nominal' => $r['nominal'], 'value' => $r['value'], 'name' => mb_substr($r['name'], 0, 190),
                'source' => 'CBAR', 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::transaction(fn () => CurrencyRate::upsert($rows, ['currency_code', 'rate_date'], ['bulletin_date', 'rate', 'nominal', 'value', 'name', 'updated_at']));
    }
}
