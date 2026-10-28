<?php

namespace App\Support\Reports;

use App\Models\Deal;
use App\Models\Expense;
use App\Services\SupplierPaymentService;

/**
 * Mənfəət və zərər (the company's «MZ» sheet), by month, in AZN — from recorded data:
 *   GƏLİRLƏR
 *     217.2  AR hüdudlarından kənarda əldə edilmiş gəlirlər — each Trade's sale at CBAR of its act date (H × BK), in the act's month
 *     212    Digər faiz gəlirləri  — money in on the bank with an income category put on 212 (e.g. a deposit's interest)
 *     214    müsbət məzənnə fərqi   — the 1C postings (TradeReport::oneC), positives
 *     219.3  mənfi məzənnə fərqi    — the 1C postings, negatives (shown with a minus, as the sheet does)
 *   XƏRCLƏR
 *     221.1.2 Əmək haqqı · 221.2 Sosial sığorta · 230.2 Sığorta haqları · 232 digər — expenses by category, at their date
 *     221.13 Bank xidməti — «Kurs fərqi» of the cash flow (the bank against CBAR) + bank fee expenses
 *     232.1  AR hüdudlarından kənarda gəlirlərin əldə edilməsi ilə bağlı xərclər — the purchase (D × BJ) and the logistics
 *            (BO) of the same Trades at CBAR of the act date
 *   Mənfəət (zərər) = gəlirlər − xərclər; MV 20 %.
 * A category goes to a line by its «MZ sətri» (categories.pl_line) or, when unset, by its name; «none» keeps it out
 * (a profit tax paid ahead, a fixed asset bought — not an expense of the period).
 */
class ProfitLossReport
{
    /** code => [label, side]; the order of the sheet. */
    public static function lines(): array
    {
        return [
            '217.2' => [__('AR hüdudlarından kənarda əldə edilmiş gəlirlər'), 'in'],
            '212' => [__('Digər faiz gəlirləri'), 'in'],
            '214' => [__('Xarici valyutaların manata nisbətən müsbət məzənnə fərqi'), 'in'],
            '219.3' => [__('Xarici valyutaların manata nisbətən məzənnəsinin dəyişməsindən yaranan mənfi fərq'), 'in'],
            '221.1.2' => [__('Əmək haqqı və ona bərabər tutulan ödəmələr'), 'out'],
            '221.2' => [__('Sosial sığorta haqları'), 'out'],
            '221.13' => [__('Bank xidməti üzrə xərclər'), 'out'],
            '230.2' => [__('Ödənilmiş sığorta haqları'), 'out'],
            '232' => [__('Digər xərclər'), 'out'],
            '232.1' => [__('AR hüdudlarından kənarda gəlirlərin əldə edilməsi ilə bağlı xərclər'), 'out'],
        ];
    }

    /** The expense lines a category can be put on. */
    public static function expenseLines(): array
    {
        return array_filter(self::lines(), fn ($l, $code) => $l[1] === 'out' && $code !== '232.1', ARRAY_FILTER_USE_BOTH);
    }

    /** «MZ sətri» of a category that stays out of the report. */
    public const NONE = 'none';

    /** A category's line: its own choice, else by its name; null — not in the report. */
    public static function lineOf(?\App\Models\Category $c): ?string
    {
        if ($c?->pl_line === self::NONE) {
            return null;
        }
        if ($c?->pl_line && isset(self::lines()[$c->pl_line])) {
            return $c->pl_line;
        }
        $name = mb_strtolower((string) $c?->name);

        return match (true) {
            str_contains($name, 'mənfəət vergisi') => null,   // the profit tax is not an expense of the period
            $c?->scope === 'income' => str_contains($name, 'faiz') ? '212' : null,
            $c?->name === SupplierPaymentService::FEE_CATEGORY, str_contains($name, 'bank') && str_contains($name, 'komis') => '221.13',
            str_contains($name, 'əmək haqq'), str_contains($name, 'maaş') => '221.1.2',
            str_contains($name, 'dsmf'), str_contains($name, 'sosial') => '221.2',
            str_contains($name, 'sığorta'), str_contains($name, 'sigorta') => '230.2',
            default => '232',
        };
    }

    /**
     * @return array{months: list<string>, lines: array<string, array{label: string, side: string, values: array<string, float>, total: float, items: array<string, list<array>>}>,
     *               in: array<string, float>, out: array<string, float>, result: array<string, float>, tax: array<string, float>}
     */
    public function build(string $from, string $to): array
    {
        $months = [];
        for ($m = \Carbon\Carbon::createFromFormat('!Y-m', $from); $m->format('Y-m') <= $to; $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }
        $lines = [];
        foreach (self::lines() as $code => [$label, $side]) {
            $lines[$code] = ['label' => $label, 'side' => $side, 'values' => array_fill_keys($months, 0.0), 'items' => []];
        }
        $add = function (?string $code, string $day, float $azn, array $why) use (&$lines, $months) {
            $m = substr($day, 0, 7);
            if ($code === null || ! in_array($m, $months, true) || abs($azn) < 0.005) {
                return;
            }
            $lines[$code]['values'][$m] += $azn;
            $lines[$code]['items'][$m][] = $why + ['d' => azdate($day), 's' => $day, 'v' => round($azn, 2)];
        };
        $end = \Carbon\Carbon::createFromFormat('!Y-m', $to)->endOfMonth()->toDateString();
        $deals = Deal::query()->get();

        // the Trades: one pass per year — the act-date amounts and the 1C postings of the year (1 January → its end)
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            $yearEnd = min("{$y}-12-31", $end);
            foreach (app(TradeReport::class)->rows($deals, null, $yearEnd) as $r) {
                $trade = 'Trade '.$r['deal']->code.' · '.$r['seller_no'];
                $act = $r['BI'] instanceof \DateTimeInterface ? $r['BI']->format('Y-m-d') : null;
                if ($act && substr($act, 0, 4) === (string) $y && $act <= $yearEnd) {
                    $add('217.2', $act, (float) $r['BM'], ['t' => $trade, 'c' => num((float) $r['H']).' '.$r['saleCur'].' × '.rate_fmt((float) $r['BK']).' CBAR · '.__('akt').' '.azdate($act)]);
                    $add('232.1', $act, (float) $r['BL'], ['t' => $trade.' · '.__('alış'), 'c' => num((float) $r['D']).' '.$r['cur'].' × '.rate_fmt((float) $r['BJ']).' CBAR']);
                    $add('232.1', $act, (float) $r['BO'], ['t' => $trade.' · '.__('logistika'), 'c' => __('logistika ödənişləri × akt gününün CBAR kursu')]);
                }
                foreach ($r['_c1'] ?? [] as $e) {
                    $add($e['diff'] > 0 ? '214' : '219.3', $e['date'], (float) $e['diff'],
                        ['t' => $trade.' · '.$e['item'], 'c' => num($e['amount']).' '.$e['cur'].' × ('.rate_fmt($e['new']).' − '.rate_fmt($e['old']).') · '.azdate($e['from']).' → '.azdate($e['date'])]);
                }
            }
        }

        // the bank against CBAR — the cash flow's «Kurs fərqi» line
        $cash = (new CashflowReport)->build($from, $to);
        foreach ($cash['payments'] as $g) {
            if ($g['kind'] === 'fx') {
                foreach ($g['details'] as $items) {
                    foreach ($items as $i) {
                        $add('221.13', $i['s'], (float) $i['v'], ['t' => __('Kurs fərqi').' · '.$i['t'], 'c' => $i['c'], 'u' => $i['u'] ?? null]);
                    }
                }
            }
        }

        // expenses, at their date, on their category's line
        $from1 = $from.'-01';
        foreach (Expense::with('category')->whereBetween('expense_date', [$from1, $end])->get() as $e) {
            $azn = (float) ($e->amount_azn ?? $e->amount);
            $add(self::lineOf($e->category), $e->expense_date->format('Y-m-d'), $azn,
                ['t' => ($e->category?->name ?? __('Xərc')).' · '.$e->description, 'c' => num((float) $e->amount).' '.$e->currency, 'u' => route('expenses.edit', $e)]);
        }

        // money in with an income category (e.g. a deposit's interest)
        foreach (\App\Models\BankTransaction::with('category')->where('direction', 'in')->where('kind', 'regular')->whereNotNull('category_id')
            ->whereBetween('transaction_date', [$from1, $end.' 23:59:59'])->get() as $t) {
            $code = self::lineOf($t->category);
            if ($code && self::lines()[$code][1] === 'in') {
                $add($code, $t->transaction_date->format('Y-m-d'), (float) $t->cbar_amount_azn, ['t' => $t->category->name.($t->purpose ? ' · '.$t->purpose : ''),
                    'c' => num((float) $t->amount).' '.$t->currency, 'u' => route('bank.transactions.show', $t)]);
            }
        }

        $in = $out = $result = $tax = [];
        foreach ($months as $m) {
            $in[$m] = round(array_sum(array_map(fn ($l) => $l['side'] === 'in' ? $l['values'][$m] : 0, $lines)), 2);
            $out[$m] = round(array_sum(array_map(fn ($l) => $l['side'] === 'out' ? $l['values'][$m] : 0, $lines)), 2);
            $result[$m] = round($in[$m] - $out[$m], 2);
            $tax[$m] = round($result[$m] * 0.2, 2);
        }
        foreach ($lines as &$l) {
            $l['values'] = array_map(fn ($v) => round($v, 2), $l['values']);
            $l['total'] = round(array_sum($l['values']), 2);
        }

        return ['months' => $months, 'lines' => $lines, 'in' => $in, 'out' => $out, 'result' => $result, 'tax' => $tax];
    }
}
