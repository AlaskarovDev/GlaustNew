<?php

namespace App\Support\Reports;

use App\Models\BankTransaction;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\LogisticsPayment;
use App\Models\SupplierPayment;
use App\Services\SupplierPaymentService;
use Illuminate\Support\Collection;

/**
 * Cash flow by month in AZN — the company's «CASH FLOW» sheet built from recorded money:
 *   Gəlirlər   — money in per counterparty, at CBAR of the day (the buyer's payments);
 *   Ödənişlər  — per counterparty: the seller (payment at CBAR, D × Y), the logistics company, other parties and
 *                expenses (by counterparty or category, cash ones too), then three lines of their own:
 *                «Ölkədaxili bank komissiyası» (bank fees not tied to a foreign transfer),
 *                «Kurs fərqi» (bank vs CBAR: currency bought / sold, seller payments at a bank cross rate),
 *                «Valyuta köçürmə komissiyası» (fees of seller and logistics transfers).
 * Transfers between own accounts and conversions are not cash flow; only their exchange result counts.
 * «Digər əməliyyat gəliri (xərci)» — the revaluation of the currency on the bank (IAS 21), the whole company only:
 *   every foreign currency is followed in lots at CBAR of the day it comes in (its AZN book value); a lot that leaves
 *   gives lot × (in rate − out rate), the balance at each month end gives book AZN − balance × the month-end rate.
 *   The bank's spread on conversions stays in «Kurs fərqi» (the conversions leave at CBAR), so nothing counts twice.
 * The opening of the first month is everything before it, so the months chain: closing = opening + in − out + other.
 */
class CashflowReport
{
    public const DOMESTIC_FEES = 'Ölkədaxili bank komissiyası';

    public const FX = 'Kurs fərqi';

    public const TRANSFER_FEES = 'Valyuta köçürmə komissiyası';

    /** @var array<string, array{label: string, kind: string, months: array<string, float>}> */
    private array $in = [];

    private array $out = [];

    /** Other operating income (+) / expense (−): the currency revaluation. */
    private array $other = [];

    /** @var list<array{0: string, 1: string}> [month, text] — what the revaluation could not settle (missing rates, more out than in) */
    private array $revalWarn = [];

    private bool $collected = false;

    /** First and last month with any money moved (Y-m), null when nothing. */
    public function span(): array
    {
        $this->collect();
        $all = array_merge([], ...array_map(fn ($g) => array_keys($g['months']), array_values($this->in + $this->out + $this->other)));

        return $all ? [min($all), max($all)] : [null, null];
    }

    public function __construct(private ?int $projectId = null) {}

    /**
     * @return array{months: list<string>, income: list<array>, payments: list<array>, opening: array<string, float>, closing: array<string, float>,
     *               in: array<string, float>, out: array<string, float>, net: array<string, float>, first: ?string, last: ?string}
     */
    public function build(string $from, string $to): array
    {
        $this->collect();
        $months = [];
        for ($m = \Carbon\Carbon::createFromFormat('!Y-m', $from); $m->format('Y-m') <= $to; $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }
        $sum = fn (array $groups, callable $keep) => array_sum(array_map(fn ($g) => array_sum(array_filter($g['months'], $keep, ARRAY_FILTER_USE_KEY)), $groups));

        // whatever happened before the first month opens it
        $opening = $sum($this->in, fn ($m) => $m < $from) - $sum($this->out, fn ($m) => $m < $from) + $sum($this->other, fn ($m) => $m < $from);
        $in = $out = $oth = $net = $open = $close = [];
        foreach ($months as $m) {
            $open[$m] = round($opening, 2);
            $in[$m] = round($sum($this->in, fn ($k) => $k === $m), 2);
            $out[$m] = round($sum($this->out, fn ($k) => $k === $m), 2);
            $oth[$m] = round($sum($this->other, fn ($k) => $k === $m), 2);
            $net[$m] = round($in[$m] - $out[$m] + $oth[$m], 2);
            $opening += $net[$m];
            $close[$m] = round($opening, 2);
        }
        $rows = fn (array $groups) => collect($groups)
            ->map(fn ($g) => $g + ['values' => array_map(fn ($m) => round($g['months'][$m] ?? 0, 2), array_combine($months, $months)),
                'details' => array_intersect_key($g['items'] ?? [], array_flip($months)),
                'total' => round(array_sum(array_intersect_key($g['months'], array_flip($months))), 2)])
            ->filter(fn ($g) => abs($g['total']) >= 0.005 || collect($g['values'])->contains(fn ($v) => abs($v) >= 0.005))
            ->sortBy([['order', 'asc'], ['total', 'desc']])->values()->all();
        [$first, $last] = $this->span();
        // what the opening of the first month is made of
        $before = ['in' => round($sum($this->in, fn ($m) => $m < $from), 2), 'out' => round($sum($this->out, fn ($m) => $m < $from), 2),
            'other' => round($sum($this->other, fn ($m) => $m < $from), 2)];

        return ['months' => $months, 'before' => $before, 'income' => $rows($this->in), 'payments' => $rows($this->out), 'other' => $rows($this->other),
            'opening' => $open, 'closing' => $close, 'in' => $in, 'out' => $out, 'oth' => $oth, 'net' => $net, 'first' => $first, 'last' => $last,
            'revalued' => $this->projectId === null, 'cash' => $this->projectId === null ? $this->cash($months) : null, 'revalWarn' => array_values(array_unique(array_column(array_filter($this->revalWarn, fn ($w) => $w[0] >= $from && $w[0] <= $to), 1)))];
    }

    /**
     * @param  array{t: string, c?: ?string, u?: ?string}  $why  one line of the cell's explanation: what, how it is computed, a link
     */
    private function add(array &$side, string $key, string $label, string $kind, $date, float $azn, int $order = 1, ?string $url = null, array $why = []): void
    {
        if (! $date || abs($azn) < 0.005) {
            return;
        }
        $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
        $m = substr($day, 0, 7);
        $side[$key] ??= ['label' => $label, 'kind' => $kind, 'order' => $order, 'url' => $url, 'months' => [], 'items' => []];
        $side[$key]['months'][$m] = ($side[$key]['months'][$m] ?? 0) + $azn;
        $side[$key]['items'][$m][] = ['d' => azdate($day), 's' => $day, 't' => $why['t'] ?? $label, 'c' => $why['c'] ?? null, 'u' => $why['u'] ?? null, 'v' => round($azn, 2)];
    }

    /**
     * Valyutanın yenidən qiymətləndirilməsi: FIFO lots per currency at their AZN book value; each lot that leaves
     * is settled at CBAR of that day, what stays is revalued at every month end. Gains go in as +, losses as −.
     */
    private function revalue(): void
    {
        $rates = app(\App\Services\Cbar\CurrencyRates::class);
        $today = $rates->today()->toDateString();
        $label = __('Valyutanın yenidən qiymətləndirilməsi');
        $fmt = fn (float $r) => rate_fmt(round($r, $r < 1 ? 7 : 4));
        $rate = fn (float $azn, float $qty) => $qty > 0 ? $fmt($azn / $qty) : '—';
        $post = function (string $day, float $loss, string $what, string $calc) use ($label) {
            $this->add($this->other, 'reval', $label, 'reval', $day, -round($loss, 2), 1, null, ['t' => $what, 'c' => $calc]);
        };

        $events = [];
        foreach (\App\Models\BankAccount::where('currency', '!=', 'AZN')->get() as $a) {
            if ((float) $a->opening_balance <= 0.005) {
                continue;
            }
            $day = $a->opening_date?->format('Y-m-d');
            $r = $day ? $rates->tryRate($a->currency, $day) : null;
            if (! $r) {
                $this->revalWarn[] = [$day ? substr($day, 0, 7) : '0000-00', __(':v1 hesabının açılış qalığının AZN dəyəri məlum deyil (açılış tarixi və ya onun CBAR kursu yoxdur) — qiymətləndirməyə daxil edilmədi.', ['v1' => $a->name])];

                continue;
            }
            $events[] = ['cur' => $a->currency, 'day' => $day, 'in' => true, 'qty' => (float) $a->opening_balance, 'azn' => round((float) $a->opening_balance * $r, 2),
                'what' => __('açılış qalığı').' · '.$a->name, 'id' => 0];
        }
        $txs = BankTransaction::where('currency', '!=', 'AZN')->where('kind', '!=', 'transfer')->get(['id', 'transaction_date', 'direction', 'kind', 'currency', 'amount', 'cbar_amount_azn', 'purpose']);
        foreach ($txs as $t) {
            $events[] = ['cur' => $t->currency, 'day' => $t->transaction_date->format('Y-m-d'), 'in' => $t->direction === 'in', 'qty' => (float) $t->amount,
                'azn' => (float) $t->cbar_amount_azn, 'what' => $t->kind === 'conversion' ? __('konvertasiya') : \Illuminate\Support\Str::limit((string) $t->purpose, 60), 'id' => $t->id];
        }
        if (! $events) {
            return;
        }
        usort($events, fn ($a, $b) => [$a['day'], $b['in'], $a['id']] <=> [$b['day'], $a['in'], $b['id']]);   // a day's money in first

        $lots = [];   // cur => list of [qty, azn, day]
        $monthEnd = function (string $me) use (&$lots, $rates, $post, $fmt) {
            foreach ($lots as $cur => &$ls) {
                $qty = array_sum(array_column($ls, 0));
                if ($qty < 0.005) {
                    continue;
                }
                $r = $rates->tryRate($cur, $me);
                if (! $r) {
                    $this->revalWarn[] = [substr($me, 0, 7), __(':v1 tarixinə :v2 CBAR kursu yoxdur — ay sonu qalığı qiymətləndirilmədi.', ['v1' => azdate($me), 'v2' => $cur])];

                    continue;
                }
                $book = array_sum(array_column($ls, 1));
                $post($me, $book - $qty * $r, __(':v1 qalığı — ay sonu', ['v1' => $cur]),
                    __('uçot').' '.num($book).' − '.num($qty).' '.$cur.' × '.rate_fmt($r));
                foreach ($ls as &$l) {
                    $l[1] = $l[0] * $r;
                }
                unset($l);
            }
            unset($ls);
        };
        // the month ends from the first money to the last month already over
        $ends = [];
        for ($m = \Carbon\Carbon::parse($events[0]['day'])->endOfMonth(); $m->toDateString() < $today; $m = $m->addDay()->endOfMonth()) {
            $ends[] = $m->toDateString();
        }
        foreach ($events as $e) {
            while ($ends && $ends[0] < $e['day']) {
                $monthEnd(array_shift($ends));
            }
            if ($e['qty'] < 0.005) {
                continue;
            }
            if ($e['in']) {
                $lots[$e['cur']][] = [$e['qty'], $e['azn'], $e['day']];

                continue;
            }
            // money out: the oldest lots first, each at its own book rate against CBAR of the day
            $left = $e['qty'];
            $outRate = $e['azn'] / $e['qty'];
            while ($left > 0.005 && ! empty($lots[$e['cur']])) {
                $l = &$lots[$e['cur']][0];
                $take = min($left, $l[0]);
                $takeAzn = $l[1] * $take / $l[0];
                $post($e['day'], $takeAzn - $take * $outRate,
                    __(':v1 partiyası :v2 → :v3', ['v1' => $e['cur'], 'v2' => azdate($l[2]), 'v3' => $e['what']]),
                    num($take).' '.$e['cur'].' × ('.$rate($takeAzn, $take).' − '.$fmt($outRate).')');
                $l[0] -= $take;
                $l[1] -= $takeAzn;
                $left -= $take;
                unset($l);
                if ($lots[$e['cur']][0][0] < 0.005) {
                    array_shift($lots[$e['cur']]);
                }
            }
            if ($left > 0.005) {
                $this->revalWarn[] = [substr($e['day'], 0, 7), __(':v1 tarixində :v2 :v3 çıxış qalıqdan çoxdur — çıxarışda daxilolma sətri və ya açılış qalığı çatışmır.', ['v1' => azdate($e['day']), 'v2' => num($left), 'v3' => $e['cur']])];
            }
        }
        foreach ($ends as $me) {
            $monthEnd($me);
        }
    }

    /**
     * «Net Cash (Xalis pul vəsaitləri) açıqlaması»: each account's balance at the month end (today for the running
     * month), in AZN at CBAR of that day — what «Dövrün sonuna qalıq» should come to.
     *
     * @return array{accounts: list<array{label: string, sub: string, currency: string, values: array<string, ?float>, native: array<string, float>, rates: array<string, ?float>}>,
     *               total: array<string, float>, day: array<string, string>}
     */
    private function cash(array $months): array
    {
        $rates = app(\App\Services\Cbar\CurrencyRates::class);
        $today = $rates->today()->toDateString();
        $day = [];
        foreach ($months as $m) {
            $end = \Carbon\Carbon::createFromFormat('!Y-m', $m)->endOfMonth()->toDateString();
            $day[$m] = min($end, $today);
        }
        $out = [];
        $total = array_fill_keys($months, 0.0);
        foreach (\App\Models\BankAccount::orderBy('bank_name')->orderBy('currency')->orderBy('name')->get() as $a) {
            $moves = BankTransaction::where('bank_account_id', $a->id)
                ->selectRaw("DATE(transaction_date) as d, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as s")->groupBy('d')->pluck('s', 'd')->all();
            $values = $native = $used = [];
            foreach ($months as $m) {
                $d = $day[$m];
                $bal = $a->opening_date === null || $a->opening_date->toDateString() <= $d ? (float) $a->opening_balance : 0.0;
                foreach ($moves as $md => $sum) {
                    if ($md <= $d) {
                        $bal += (float) $sum;
                    }
                }
                $native[$m] = round($bal, 2);
                $r = $a->currency === 'AZN' ? 1.0 : (abs($bal) >= 0.005 ? $rates->tryRate($a->currency, $d) : null);
                $used[$m] = $r;
                $values[$m] = abs($bal) < 0.005 ? 0.0 : ($r === null ? null : round($bal * $r, 2));
                $total[$m] += $values[$m] ?? 0;
            }
            if (collect($native)->contains(fn ($v) => abs($v) >= 0.005)) {
                $out[] = ['label' => $a->name, 'sub' => $a->bank_name, 'currency' => $a->currency, 'values' => $values, 'native' => $native, 'rates' => $used];
            }
        }

        return ['accounts' => $out, 'total' => array_map(fn ($v) => round($v, 2), $total), 'day' => $day];
    }

    /** «1 000,00 EUR × 1,7000 CBAR» */
    private static function calc($amount, ?string $cur, $rate, string $what = 'CBAR'): string
    {
        return num((float) $amount).' '.$cur.($cur === 'AZN' || ! $rate ? '' : ' × '.rate_fmt((float) $rate).' '.$what);
    }

    private function collect(): void
    {
        if ($this->collected) {
            return;
        }
        $this->collected = true;
        $p = $this->projectId;
        $supplier = SupplierPayment::with('deal.supplier', 'deal:id,code,supplier_id,project_id')->when($p, fn ($q) => $q->whereHas('deal', fn ($d) => $d->where('project_id', $p)))->get();
        $logistics = LogisticsPayment::with('act.counterparty', 'act.deal:id,code')->when($p, fn ($q) => $q->whereHas('act.deal', fn ($d) => $d->where('project_id', $p)))->get();
        $handled = $supplier->pluck('transaction_id')->merge($logistics->pluck('transaction_id'))->filter()->all();
        $transferFees = $supplier->pluck('fee_expense_id')->merge($logistics->pluck('fee_expense_id'))->filter()->all();
        $expenses = Expense::with('category', 'counterparty')->where('status', 'paid')->when($p, fn ($q) => $q->where('project_id', $p))->get();
        $expenseTx = $expenses->pluck('bank_transaction_id')->filter()->all();
        $party = fn ($c) => $c ? ['cp-'.$c->id, $c->name, route('counterparties.show', $c)] : null;

        // money in, at CBAR of the day
        $txs = BankTransaction::with('counterparty')->where('kind', 'regular')->when($p, fn ($q) => $q->where('project_id', $p))->get();
        foreach ($txs->where('direction', 'in') as $t) {
            [$key, $label, $url] = $party($t->counterparty) ?? ['in-other', __('Digər daxilolmalar'), null];
            $this->add($this->in, $key, $label, 'party', $t->transaction_date, (float) $t->cbar_amount_azn, 1, $url,
                ['t' => __('Bank daxilolması').($t->purpose ? ' · '.$t->purpose : '').($t->reference ? ' · '.$t->reference : ''), 'c' => self::calc($t->amount, $t->currency, $t->cbar_rate)]);
        }

        // the seller: the payment at CBAR (D × Y); a bank cross rate's difference goes to «Kurs fərqi»
        foreach ($supplier as $s) {
            [$key, $label, $url] = $party($s->deal?->supplier) ?? ['out-seller', __('Satıcılar'), null];
            $trade = $s->deal ? ['u' => route('deals.show', $s->deal)] : [];
            $this->add($this->out, $key, $label, 'party', $s->payment_date, (float) $s->amount * (float) $s->cbar_rate, 1, $url,
                ['t' => __('Satıcıya ödəniş').($s->deal ? ' · Trade '.$s->deal->code : ''), 'c' => 'D × Y = '.self::calc($s->amount, $s->currency, $s->cbar_rate)] + $trade);
            $this->add($this->out, 'fx', __(self::FX), 'fx', $s->payment_date, (float) $s->difference_azn, 9, null,
                ['t' => __('Satıcıya ödəniş — bankın çarpaz kursu ilə CBAR fərqi').($s->deal ? ' · Trade '.$s->deal->code : ''),
                    'c' => '('.num((float) $s->account_amount).' '.__('bankla').' − '.num((float) $s->account_amount_cbar).' CBAR) '.$s->account_currency
                        .($s->account_currency === 'AZN' ? '' : ' × '.rate_fmt((float) $s->cbar_account_rate))] + $trade);
        }
        // the logistics company: what left the account, at CBAR of the day
        $logTx = BankTransaction::whereIn('id', $logistics->pluck('transaction_id')->filter())->get()->keyBy('id');
        foreach ($logistics as $l) {
            [$key, $label, $url] = $party($l->act?->counterparty) ?? ['out-logistics', __('Logistika'), null];
            $azn = ($tx = $logTx[$l->transaction_id] ?? null) ? (float) $tx->cbar_amount_azn : (float) $l->amount * (float) $l->cbar_rate;
            $this->add($this->out, $key, $label, 'party', $l->payment_date, $azn, 1, $url,
                ['t' => __('Logistikaya ödəniş').($l->act?->act_number ? ' · '.__('Akt').' '.$l->act->act_number : '').($l->act?->deal ? ' · Trade '.$l->act->deal->code : ''),
                    'c' => $tx ? self::calc($tx->amount, $tx->currency, $tx->cbar_rate) : self::calc($l->amount, $l->currency, $l->cbar_rate)]
                    + ($l->act?->deal ? ['u' => route('deals.show', $l->act->deal)] : []));
        }
        // expenses (bank or cash): the bank's fees on their own lines, others by counterparty or category
        foreach ($expenses as $e) {
            $date = $e->paid_at ?? $e->expense_date;
            $azn = (float) ($e->amount_azn ?? $e->amount);
            $why = ['t' => ($e->category?->name ?? __('Xərc')).($e->description ? ' · '.$e->description : '').($e->bank_transaction_id ? '' : ' · '.__('nağd')),
                'c' => self::calc($e->amount, $e->currency, $e->currency === 'AZN' ? null : $e->cbar_rate)];
            if ($e->category?->name === SupplierPaymentService::FEE_CATEGORY) {
                in_array($e->id, $transferFees, true)
                    ? $this->add($this->out, 'transfer-fees', __(self::TRANSFER_FEES), 'fees', $date, $azn, 10, null, $why)
                    : $this->add($this->out, 'domestic-fees', __(self::DOMESTIC_FEES), 'fees', $date, $azn, 8, null, $why);

                continue;
            }
            if ($e->counterparty) {
                [$key, $label, $url] = $party($e->counterparty);
                $this->add($this->out, $key, $label, 'party', $date, $azn, 1, $url, $why);
            } else {
                $this->add($this->out, 'cat-'.($e->category_id ?? 0), $e->category?->name ?? __('Digər xərclər'), 'category', $date, $azn, 2, null, $why);
            }
        }
        // any other money out (not a seller / logistics payment, not an expense)
        foreach ($txs->where('direction', 'out')->whereNotIn('id', array_merge($handled, $expenseTx)) as $t) {
            [$key, $label, $url] = $party($t->counterparty) ?? ['out-other', __('Digər ödənişlər'), null];
            $this->add($this->out, $key, $label, 'party', $t->transaction_date, (float) $t->cbar_amount_azn, 1, $url,
                ['t' => __('Bank məxarici').($t->purpose ? ' · '.$t->purpose : '').($t->reference ? ' · '.$t->reference : ''), 'c' => self::calc($t->amount, $t->currency, $t->cbar_rate)]);
        }
        // the currency on the bank, revalued — accounts belong to the whole company, so only without a project filter
        if (! $p) {
            $this->revalue();
        }

        // currency bought / sold: the bank against CBAR (+ loss, − gain)
        foreach (CurrencyExchange::when($p, fn ($q) => $q->where('project_id', $p))->get() as $x) {
            $this->add($this->out, 'fx', __(self::FX), 'fx', $x->exchange_date, (float) $x->difference_azn, 9, null,
                ['t' => ($x->direction === 'sell' ? __('Valyuta satışı') : __('Valyuta alışı')).' · '.num((float) $x->amount).' '.$x->currency.($x->reference ? ' · '.$x->reference : ''),
                    'c' => num((float) $x->amount).' '.$x->currency.' × '.($x->direction === 'sell'
                        ? '('.rate_fmt((float) $x->cbar_cross).' CBAR − '.rate_fmt((float) $x->bank_rate).' '.__('bank').')'
                        : '('.rate_fmt((float) $x->bank_rate).' '.__('bank').' − '.rate_fmt((float) $x->cbar_cross).' CBAR)').($x->counter_currency !== 'AZN' ? ' '.$x->counter_currency.' × '.rate_fmt((float) $x->cbar_counter_rate) : '')]
                + ($x->deal_id ? ['u' => route('deals.show', $x->deal_id)] : []));
        }
    }
}
