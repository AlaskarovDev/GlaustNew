<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Exchange-rate difference per the Tax Code (VM 69, 13.2.12, 108.1). Pure arithmetic — rates are given:
 *   alis_borc        — purchase, goods first, paid later (liability; revalued on every 31.12 in between);
 *   satis_borc       — sale, goods first, money later (asset; revalued on every 31.12 in between);
 *   verilmis_avans   — advance paid, goods/services received later (never revalued on 31.12);
 *   alinmis_avans    — advance received, goods delivered later (never revalued on 31.12);
 *   il_sonu_aktiv    — 31.12 revaluation of an open receivable / currency account balance;
 *   il_sonu_ohdelik  — 31.12 revaluation of an open payable / currency loan.
 * Sign: the company gets more manat or pays fewer → positive (income, line 214);
 * gets fewer or pays more → negative (expense, line 219.3). Same day → no difference.
 */
class FxDifference
{
    public const CASES = ['verilmis_avans', 'alis_borc', 'satis_borc', 'alinmis_avans', 'il_sonu_aktiv', 'il_sonu_ohdelik'];

    public const LINES = [
        'positive' => ['profit' => '214', 'income' => '1212'],
        'negative' => ['profit' => '219.3', 'income' => '1224.5'],
    ];

    public static function labels(): array
    {
        return [
            'verilmis_avans' => __('Verilmiş avans — əvvəl ödəniş, sonra mal/xidmət'),
            'alis_borc' => __('Alış — əvvəl mal/xidmət, sonra ödəniş (kreditor borcu)'),
            'satis_borc' => __('Satış — əvvəl mal/xidmət, sonra pul daxil olur (debitor borcu)'),
            'alinmis_avans' => __('Alınmış avans — əvvəl pul daxil olur, sonra mal/xidmət'),
            'il_sonu_aktiv' => __('İl sonu: debitor borcu / valyuta hesabı qalığı'),
            'il_sonu_ohdelik' => __('İl sonu: kreditor borcu / valyuta krediti'),
        ];
    }

    /** Which date is which, for the form and the result. */
    public static function dateLabels(string $case): array
    {
        return match ($case) {
            'verilmis_avans' => [__('Ödəniş (avans)'), __('Mal/xidmətin qəbulu')],
            'alis_borc' => [__('Mal/xidmətin qəbulu'), __('Ödəniş')],
            'satis_borc' => [__('Mal/xidmətin təqdimi'), __('Pulun daxil olması')],
            'alinmis_avans' => [__('Avansın daxil olması'), __('Mal/xidmətin təqdimi')],
            default => [__('Uçota alınma'), '31.12'],
        };
    }

    /**
     * @param  array<int, float>  $yearEnd  31.12 rate of every year between the two dates (year => rate), debts only
     * @return array{steps: list<array>, positive: float, negative: float, net: float, book: float, settled: float,
     *               recognized: float|null, paid: float|null, tax_base: float|null, tax_base_date: string|null}
     */
    public static function calc(string $case, float $amount, string $date1, float $rate1, string $date2, float $rate2,
        array $yearEnd = [], bool $nonResidentService = false): array
    {
        $d1 = CarbonImmutable::parse($date1);
        $d2 = CarbonImmutable::parse($date2);
        $book = round($amount * $rate1, 2);
        $settled = round($amount * $rate2, 2);
        $steps = [];

        // + means the company is better off (positive difference)
        $step = function (string $date, string $why, float $from, float $to, float $gain) use (&$steps) {
            $gain = round($gain, 2);
            $steps[] = ['date' => $date, 'year' => (int) substr($date, 0, 4), 'why' => $why, 'rate_from' => $from, 'rate_to' => $to,
                'amount' => abs($gain), 'kind' => abs($gain) < 0.005 ? 'none' : ($gain > 0 ? 'positive' : 'negative'),
                'line' => abs($gain) < 0.005 ? null : self::LINES[$gain > 0 ? 'positive' : 'negative']];
        };

        $asset = in_array($case, ['satis_borc', 'il_sonu_aktiv'], true);
        if ($d1->isSameDay($d2)) {
            // same day: nothing
        } elseif (in_array($case, ['alis_borc', 'satis_borc'], true)) {
            $prev = $rate1;
            for ($y = $d1->year; $y < $d2->year; $y++) {      // open debt on every 31.12 in between (VM 69.2)
                if (! isset($yearEnd[$y])) {
                    continue;
                }
                $r = $yearEnd[$y];
                $diff = $amount * ($r - $prev);
                $step("{$y}-12-31", __('31.12 yenidən qiymətləndirmə'), $prev, $r, $asset ? $diff : -$diff);
                $prev = $r;
            }
            $diff = $amount * ($rate2 - $prev);
            $step($d2->toDateString(), $asset ? __('Pulun daxil olması') : __('Ödəniş'), $prev, $rate2, $asset ? $diff : -$diff);
        } elseif ($case === 'verilmis_avans') {
            $step($d2->toDateString(), __('Mal/xidmətin qəbulu'), $rate1, $rate2, $settled - $book);   // value received vs manat paid
        } elseif ($case === 'alinmis_avans') {
            $step($d2->toDateString(), __('Mal/xidmətin təqdimi'), $rate1, $rate2, $book - $settled);  // manat received vs income booked
        } else {
            $diff = $amount * ($rate2 - $rate1);
            $step($d2->toDateString(), __('31.12 yenidən qiymətləndirmə'), $rate1, $rate2, $asset ? $diff : -$diff);
        }

        $pos = round(collect($steps)->where('kind', 'positive')->sum('amount'), 2);
        $neg = round(collect($steps)->where('kind', 'negative')->sum('amount'), 2);

        // what lands in income / expense and how much manat actually moved
        [$recognized, $paid] = match ($case) {
            'verilmis_avans' => [$settled, $book],     // expense at receipt; manat paid with the advance
            'alis_borc' => [$book, $settled],          // expense at receipt; manat paid at payment
            'satis_borc' => [$book, $settled],         // income at delivery; manat received
            'alinmis_avans' => [$settled, $book],      // income at delivery; manat received with the advance
            default => [null, null],
        };
        $taxDate = match ($case) { 'verilmis_avans' => $d1, 'alis_borc' => $d2, default => null };

        return [
            'steps' => $steps, 'positive' => $pos, 'negative' => $neg, 'net' => round($pos - $neg, 2),
            'book' => $book, 'settled' => $settled, 'recognized' => $recognized, 'paid' => $paid,
            // non-resident service: VAT (agent) and withholding on the manat paid, at the payment day's rate
            'tax_base' => $nonResidentService && $taxDate ? ($case === 'verilmis_avans' ? $book : $settled) : null,
            'tax_base_date' => $nonResidentService && $taxDate ? $taxDate->toDateString() : null,
        ];
    }
}
