<?php

namespace App\Support;

/**
 * Russian amount in words, as printed on contract specifications:
 * 19800178.00 -> "девятнадцать миллионов восемьсот тысяч сто семьдесят восемь рублей ноль копеек".
 */
class RuMoney
{
    private const ONES = [
        'm' => ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
        'f' => ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'],
    ];

    private const TEENS = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];

    private const TENS = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];

    private const HUNDREDS = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];

    /** [gender, one, few, many] per power of thousand */
    private const SCALES = [
        1 => ['f', 'тысяча', 'тысячи', 'тысяч'],
        2 => ['m', 'миллион', 'миллиона', 'миллионов'],
        3 => ['m', 'миллиард', 'миллиарда', 'миллиардов'],
        4 => ['m', 'триллион', 'триллиона', 'триллионов'],
    ];

    private const CURRENCIES = [
        'RUB' => [['m', 'рубль', 'рубля', 'рублей'], ['f', 'копейка', 'копейки', 'копеек']],
        'EUR' => [['m', 'евро', 'евро', 'евро'], ['m', 'цент', 'цента', 'центов']],
        'USD' => [['m', 'доллар', 'доллара', 'долларов'], ['m', 'цент', 'цента', 'центов']],
        'AZN' => [['m', 'манат', 'маната', 'манатов'], ['m', 'гяпик', 'гяпика', 'гяпиков']],
    ];

    public static function words(float|string $amount, string $currency = 'RUB'): string
    {
        $cents = (int) round(abs((float) $amount) * 100);
        $whole = intdiv($cents, 100);
        $fraction = $cents % 100;
        [$major, $minor] = self::CURRENCIES[$currency] ?? self::CURRENCIES['RUB'];

        $text = self::integer($whole, $major[0]).' '.self::plural($whole, $major)
            .' '.self::integer($fraction, $minor[0]).' '.self::plural($fraction, $minor);

        return ((float) $amount < 0 ? 'минус ' : '').preg_replace('/\s+/u', ' ', trim($text));
    }

    /** Cardinal number in words; $gender agrees with the noun that follows. */
    public static function integer(int $n, string $gender = 'm'): string
    {
        if ($n === 0) {
            return 'ноль';
        }
        $parts = [];
        $scale = 0;
        while ($n > 0) {
            $triad = $n % 1000;
            if ($triad > 0) {
                $g = $scale === 0 ? $gender : self::SCALES[$scale][0];
                $words = self::triad($triad, $g);
                if ($scale > 0) {
                    $words .= ' '.self::plural($triad, self::SCALES[$scale]);
                }
                array_unshift($parts, $words);
            }
            $n = intdiv($n, 1000);
            $scale++;
        }

        return implode(' ', $parts);
    }

    private static function triad(int $n, string $gender): string
    {
        $out = [self::HUNDREDS[intdiv($n, 100)]];
        $rest = $n % 100;
        if ($rest >= 10 && $rest < 20) {
            $out[] = self::TEENS[$rest - 10];
        } else {
            $out[] = self::TENS[intdiv($rest, 10)];
            $out[] = self::ONES[$gender][$rest % 10];
        }

        return implode(' ', array_filter($out));
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string}  $forms  [gender, one, few, many] */
    private static function plural(int $n, array $forms): string
    {
        $n100 = $n % 100;
        $n10 = $n % 10;
        if ($n100 >= 11 && $n100 <= 14) {
            return $forms[3];
        }

        return match (true) {
            $n10 === 1 => $forms[1],
            $n10 >= 2 && $n10 <= 4 => $forms[2],
            default => $forms[3],
        };
    }
}
