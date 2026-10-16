<?php

namespace App\Support\BankAudit;

use App\Models\BankAudit;
use App\Models\BankTransaction;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\LogisticsPayment;
use App\Models\SupplierPayment;
use Illuminate\Support\Collection;

/**
 * Bank hesabları auditi: the statement's lines against the system's movements of the same account, each time fresh.
 *
 *   1. the same day and the same amount                                         → uyğun
 *   2. one bank line = several system movements of that day (a purchase split by Trade)  → uyğun (bölünüb)
 *   3. one system movement = 2–4 bank lines of that day (a conversion + «MultiCurrency Auto Transaction») → uyğun (birləşib)
 *   4. the same amount up to 15 days apart                                       → tarix fərqlidir
 *      one bank line = parts booked on nearby days (a payment split by Trade, one part a day early) → tarix fərqlidir
 *      one movement = bank lines of nearby days, or several = several (a fee charged 293.04 + 6.96, booked
 *      149.80 + 150.20): the same money cut differently in time                                  → uyğun (bölgü fərqli)
 *   5. the same day, the same direction, the amount off by ≤ 2 % (at least 1.00) → məbləğ fərqlidir
 *   6. what is left: a bank line the system lacks / a system movement the bank never made
 * Plus the balances: the opening, the closing and the first day the end-of-day balance parts ways.
 */
class Reconciler
{
    public const WINDOW = 15;

    /** @var array<int, array{label: string, url: ?string, kind: string}> tx id => what it belongs to */
    private array $sources = [];

    public function __construct(private BankAudit $audit) {}

    /**
     * @return array{rows: list<array>, counts: array<string, int>, balances: array, matchedAmount: float}
     */
    public function run(): array
    {
        $audit = $this->audit;
        $account = $audit->account;
        $from = $audit->period_from->toDateString();
        $to = $audit->period_to->toDateString();
        $lines = $audit->lines()->orderBy('line_date')->orderByDesc('line_no')->get()
            ->map(fn ($l) => ['id' => $l->id, 'date' => $l->line_date->toDateString(), 'amount' => (float) $l->amount, 'm' => $l])->keyBy('id')->all();
        $pool = BankTransaction::where('bank_account_id', $account->id)
            ->whereBetween('transaction_date', [date('Y-m-d', strtotime($from.' -'.self::WINDOW.' days')), date('Y-m-d', strtotime($to.' +'.self::WINDOW.' days')).' 23:59:59'])
            ->orderBy('transaction_date')->orderBy('id')->get();
        $txs = $pool->map(fn ($t) => ['id' => $t->id, 'date' => $t->transaction_date->toDateString(), 'amount' => ($t->direction === 'in' ? 1 : -1) * (float) $t->amount, 'm' => $t])
            ->keyBy('id')->all();
        $this->loadSources(array_keys($txs));

        $rows = [];
        $freeL = $lines;
        $freeT = $txs;
        $eq = fn (float $a, float $b) => abs($a - $b) < 0.005;
        $byDate = fn (array $set, string $d) => array_filter($set, fn ($x) => $x['date'] === $d);
        $take = function (string $status, array $ls, array $ts, ?array $fix = null) use (&$rows, &$freeL, &$freeT) {
            foreach ($ls as $l) {
                unset($freeL[$l['id']]);
            }
            foreach ($ts as $t) {
                unset($freeT[$t['id']]);
            }
            $rows[] = ['status' => $status, 'lines' => array_values($ls), 'txs' => array_values($ts), 'fix' => $fix];
        };

        // 1. one to one
        foreach ($lines as $l) {
            foreach ($byDate($freeT, $l['date']) as $t) {
                if ($eq($l['amount'], $t['amount'])) {
                    $take('ok', [$l], [$t]);
                    break;
                }
            }
        }
        // 2. one bank line, several system movements of the day (same direction)
        foreach ($freeL as $l) {
            $cand = array_values(array_filter($byDate($freeT, $l['date']), fn ($t) => $t['amount'] * $l['amount'] > 0));
            if (count($cand) >= 2 && count($cand) <= 12 && ($set = self::subset($cand, $l['amount'], 4))) {
                $take('split', [$l], $set);
            }
        }
        // 3. one system movement, 2–4 bank lines of the day (a conversion and its «MultiCurrency» line)
        foreach ($freeT as $t) {
            $cand = array_values($byDate($freeL, $t['date']));
            if (count($cand) >= 2 && count($cand) <= 15 && ($set = self::subset($cand, $t['amount'], 4))) {
                $take('joined', $set, [$t]);
            }
        }
        // 4. the same amount on another day
        foreach ($freeL as $l) {
            $best = null;
            foreach ($freeT as $t) {
                $days = abs(strtotime($t['date']) - strtotime($l['date'])) / 86400;
                if ($eq($l['amount'], $t['amount']) && $days <= self::WINDOW && ($best === null || $days < $best[1])) {
                    $best = [$t, $days];
                }
            }
            if ($best) {
                $take('date', [$l], [$best[0]], ['action' => 'date', 'tx' => $best[0]['id'], 'line' => $l['id']]);
            }
        }
        // 4b. one bank line, parts booked on nearby days: the off-day parts move to the bank's day
        $dist = fn (array $x, string $d) => abs(strtotime($x['date']) - strtotime($d));
        $near = function (array $set, array $of) use ($dist) {
            $c = array_filter($set, fn ($x) => $x['amount'] * $of['amount'] > 0 && $dist($x, $of['date']) <= self::WINDOW * 86400);
            usort($c, fn ($a, $b) => $dist($a, $of['date']) <=> $dist($b, $of['date']));

            return array_slice($c, 0, 14);
        };
        foreach ($freeL as $l) {
            if (isset($freeL[$l['id']]) && ($set = self::subset($near($freeT, $l), $l['amount'], 4))) {
                $moved = array_values(array_filter($set, fn ($t) => $t['date'] !== $l['date']));
                $take('date', [$l], $set, ['action' => 'date', 'txs' => array_column($moved, 'id'), 'line' => $l['id']]);
            }
        }
        // 4c. one movement, bank lines of nearby days — the bank cut it in time
        foreach ($freeT as $t) {
            if (isset($freeT[$t['id']]) && ($set = self::subset($near($freeL, $t), $t['amount'], 4))) {
                $take('regroup', $set, [$t]);
            }
        }
        // 4d. several bank lines = several movements of nearby days, cut differently
        foreach ($freeL as $l) {
            if (! isset($freeL[$l['id']])) {
                continue;
            }
            $others = array_values(array_filter($near($freeL, $l), fn ($x) => $x['id'] !== $l['id']));
            $ts = $near($freeT, $l);
            foreach (array_merge([[]], array_map(fn ($x) => [$x], $others)) as $extra) {
                $group = [$l, ...$extra];
                $sum = array_sum(array_column($group, 'amount'));
                if ($set = self::subset($ts, $sum, 3, count($group) > 1 ? 1 : 2)) {
                    $take('regroup', $group, $set);
                    break;
                }
            }
            if (! isset($freeL[$l['id']])) {
                continue;
            }
            for ($i = 0; $i < count($others); $i++) {
                for ($j = $i + 1; $j < count($others); $j++) {
                    $group = [$l, $others[$i], $others[$j]];
                    if (isset($freeL[$l['id']]) && ($set = self::subset($ts, array_sum(array_column($group, 'amount')), 3, 1))) {
                        $take('regroup', $group, $set);
                        break 2;
                    }
                }
            }
        }
        // 5. the same day and direction, a slightly different amount
        foreach ($freeL as $l) {
            $best = null;
            foreach ($byDate($freeT, $l['date']) as $t) {
                $diff = abs($l['amount'] - $t['amount']);
                if ($t['amount'] * $l['amount'] > 0 && $diff <= max(1.0, 0.02 * abs($l['amount'])) && ($best === null || $diff < $best[1])) {
                    $best = [$t, $diff];
                }
            }
            if ($best) {
                $plain = ! isset($this->sources[$best[0]['id']]) && $best[0]['m']->kind === 'regular';
                $take('amount', [$l], [$best[0]], $plain ? ['action' => 'amount', 'tx' => $best[0]['id'], 'line' => $l['id']] : null);
            }
        }
        // 6. the rest
        foreach ($freeL as $l) {
            $take('missing_sys', [$l], [], ['action' => 'create', 'line' => $l['id']]);
        }
        foreach ($freeT as $t) {
            if ($t['date'] >= $from && $t['date'] <= $to) {
                $take('missing_bank', [], [$t], ['action' => 'delete', 'tx' => $t['id']]);
            }
        }

        usort($rows, fn ($a, $b) => [self::day($a), self::rank($a)] <=> [self::day($b), self::rank($b)]);
        $counts = array_count_values(array_column($rows, 'status'));

        return ['rows' => $rows, 'counts' => $counts, 'balances' => $this->balances($lines, $from, $to), 'sources' => $this->sources];
    }

    /** The statuses that mean «it matches». */
    public const MATCHED = ['ok', 'split', 'joined', 'regroup'];

    /** Problems first within a day. */
    private static function rank(array $row): int
    {
        return in_array($row['status'], self::MATCHED, true) ? 1 : 0;
    }

    private static function day(array $row): string
    {
        return ($row['lines'][0] ?? $row['txs'][0])['date'];
    }

    /** A subset of $min..$max items whose amounts add up to $target, null when none. */
    private static function subset(array $items, float $target, int $max, int $min = 2): ?array
    {
        $items = array_values($items);
        $n = count($items);
        $found = null;
        $walk = function (int $start, array $picked, float $sum) use (&$walk, &$found, $items, $n, $target, $max, $min) {
            if ($found !== null) {
                return;
            }
            if (count($picked) >= $min && abs($sum - $target) < 0.015) {
                $found = $picked;

                return;
            }
            if (count($picked) === $max) {
                return;
            }
            for ($i = $start; $i < $n; $i++) {
                $walk($i + 1, [...$picked, $items[$i]], $sum + $items[$i]['amount']);
            }
        };
        $walk(0, [], 0.0);

        return $found;
    }

    /** What each movement belongs to: a seller or logistics payment, an expense, a currency exchange. */
    private function loadSources(array $ids): void
    {
        if (! $ids) {
            return;
        }
        foreach (SupplierPayment::with('deal:id,code')->whereIn('transaction_id', $ids)->get() as $p) {
            $this->sources[$p->transaction_id] = ['kind' => 'supplier', 'id' => $p->id, 'label' => __('Satıcıya ödəniş').($p->deal ? ' · Trade '.$p->deal->code : ''),
                'url' => $p->deal ? route('deals.show', $p->deal) : null];
        }
        foreach (LogisticsPayment::with('act.deal:id,code')->whereIn('transaction_id', $ids)->get() as $p) {
            $deal = $p->act?->deal;
            $this->sources[$p->transaction_id] = ['kind' => 'logistics', 'id' => $p->id, 'label' => __('Logistikaya ödəniş').($deal ? ' · Trade '.$deal->code : ''),
                'url' => $deal ? route('deals.show', $deal) : null];
        }
        foreach (Expense::with('category:id,name')->whereIn('bank_transaction_id', $ids)->get() as $e) {
            $this->sources[$e->bank_transaction_id] ??= ['kind' => 'expense', 'id' => $e->id, 'label' => __('Xərc').' · '.($e->category?->name ?? $e->description),
                'url' => route('expenses.edit', $e)];
        }
        foreach (CurrencyExchange::whereIn('out_transaction_id', $ids)->orWhereIn('in_transaction_id', $ids)->get() as $x) {
            foreach ([$x->out_transaction_id, $x->in_transaction_id] as $tid) {
                if (in_array($tid, $ids, true)) {
                    $this->sources[$tid] = ['kind' => 'exchange', 'id' => $x->id, 'label' => __(CurrencyExchange::DIRECTIONS[$x->direction] ?? 'Valyuta').' · '.num((float) $x->amount).' '.$x->currency,
                        'url' => route('bank.exchanges.index')];
                }
            }
        }
    }

    /** The statement's balances against the system's: the opening, the closing, the first day they part ways. */
    private function balances(array $lines, string $from, string $to): array
    {
        $account = $this->audit->account;
        $moves = BankTransaction::where('bank_account_id', $account->id)->where('transaction_date', '<=', $to.' 23:59:59')
            ->selectRaw("DATE(transaction_date) as d, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as s")->groupBy('d')->pluck('s', 'd')->all();
        $at = function (string $day) use ($moves, $account) {
            $sum = $account->opening_date === null || $account->opening_date->toDateString() <= $day ? (float) $account->opening_balance : 0.0;
            foreach ($moves as $d => $s) {
                if ($d <= $day) {
                    $sum += (float) $s;
                }
            }

            return round($sum, 2);
        };
        $before = date('Y-m-d', strtotime($from.' -1 day'));

        // end of each statement day: the balance after its newest line (the smallest «№»)
        $ends = [];
        foreach ($lines as $l) {
            $m = $l['m'];
            if ($m->balance !== null && (! isset($ends[$l['date']]) || $m->line_no < $ends[$l['date']][0])) {
                $ends[$l['date']] = [$m->line_no, (float) $m->balance];
            }
        }
        ksort($ends);
        $split = null;
        foreach ($ends as $d => [, $bank]) {
            if (abs($at($d) - $bank) >= 0.01) {
                $split = ['date' => $d, 'bank' => $bank, 'system' => $at($d)];
                break;
            }
        }

        return [
            'opening' => ['bank' => (float) $this->audit->opening, 'system' => $at($before), 'date' => $before],
            'closing' => ['bank' => (float) $this->audit->closing, 'system' => $at($to), 'date' => $to],
            'split' => $split,
        ];
    }
}
