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
 * The opening of the first month is everything before it, so the months chain: closing = opening + in − out.
 */
class CashflowReport
{
    public const DOMESTIC_FEES = 'Ölkədaxili bank komissiyası';

    public const FX = 'Kurs fərqi';

    public const TRANSFER_FEES = 'Valyuta köçürmə komissiyası';

    /** @var array<string, array{label: string, kind: string, months: array<string, float>}> */
    private array $in = [];

    private array $out = [];

    private bool $collected = false;

    /** First and last month with any money moved (Y-m), null when nothing. */
    public function span(): array
    {
        $this->collect();
        $all = array_merge([], ...array_map(fn ($g) => array_keys($g['months']), array_values($this->in + $this->out)));

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
        $opening = $sum($this->in, fn ($m) => $m < $from) - $sum($this->out, fn ($m) => $m < $from);
        $in = $out = $net = $open = $close = [];
        foreach ($months as $m) {
            $open[$m] = round($opening, 2);
            $in[$m] = round($sum($this->in, fn ($k) => $k === $m), 2);
            $out[$m] = round($sum($this->out, fn ($k) => $k === $m), 2);
            $net[$m] = round($in[$m] - $out[$m], 2);
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
        $before = ['in' => round($sum($this->in, fn ($m) => $m < $from), 2), 'out' => round($sum($this->out, fn ($m) => $m < $from), 2)];

        return ['months' => $months, 'before' => $before, 'income' => $rows($this->in), 'payments' => $rows($this->out),
            'opening' => $open, 'closing' => $close, 'in' => $in, 'out' => $out, 'net' => $net, 'first' => $first, 'last' => $last];
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
