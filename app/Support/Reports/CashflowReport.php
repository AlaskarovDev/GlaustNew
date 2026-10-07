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
                'total' => round(array_sum(array_intersect_key($g['months'], array_flip($months))), 2)])
            ->filter(fn ($g) => abs($g['total']) >= 0.005 || collect($g['values'])->contains(fn ($v) => abs($v) >= 0.005))
            ->sortBy([['order', 'asc'], ['total', 'desc']])->values()->all();
        [$first, $last] = $this->span();

        return ['months' => $months, 'income' => $rows($this->in), 'payments' => $rows($this->out),
            'opening' => $open, 'closing' => $close, 'in' => $in, 'out' => $out, 'net' => $net, 'first' => $first, 'last' => $last];
    }

    private function add(array &$side, string $key, string $label, string $kind, $date, float $azn, int $order = 1, ?string $url = null): void
    {
        if (! $date || abs($azn) < 0.005) {
            return;
        }
        $m = $date instanceof \DateTimeInterface ? $date->format('Y-m') : substr((string) $date, 0, 7);
        $side[$key] ??= ['label' => $label, 'kind' => $kind, 'order' => $order, 'url' => $url, 'months' => []];
        $side[$key]['months'][$m] = ($side[$key]['months'][$m] ?? 0) + $azn;
    }

    private function collect(): void
    {
        if ($this->collected) {
            return;
        }
        $this->collected = true;
        $p = $this->projectId;
        $supplier = SupplierPayment::with('deal.supplier')->when($p, fn ($q) => $q->whereHas('deal', fn ($d) => $d->where('project_id', $p)))->get();
        $logistics = LogisticsPayment::with('act.counterparty')->when($p, fn ($q) => $q->whereHas('act.deal', fn ($d) => $d->where('project_id', $p)))->get();
        $handled = $supplier->pluck('transaction_id')->merge($logistics->pluck('transaction_id'))->filter()->all();
        $transferFees = $supplier->pluck('fee_expense_id')->merge($logistics->pluck('fee_expense_id'))->filter()->all();
        $expenses = Expense::with('category', 'counterparty')->where('status', 'paid')->when($p, fn ($q) => $q->where('project_id', $p))->get();
        $expenseTx = $expenses->pluck('bank_transaction_id')->filter()->all();
        $party = fn ($c) => $c ? ['cp-'.$c->id, $c->name, route('counterparties.show', $c)] : null;

        // money in, at CBAR of the day
        $txs = BankTransaction::with('counterparty')->where('kind', 'regular')->when($p, fn ($q) => $q->where('project_id', $p))->get();
        foreach ($txs->where('direction', 'in') as $t) {
            [$key, $label, $url] = $party($t->counterparty) ?? ['in-other', __('Digər daxilolmalar'), null];
            $this->add($this->in, $key, $label, 'party', $t->transaction_date, (float) $t->cbar_amount_azn, 1, $url);
        }

        // the seller: the payment at CBAR (D × Y); a bank cross rate's difference goes to «Kurs fərqi»
        foreach ($supplier as $s) {
            [$key, $label, $url] = $party($s->deal?->supplier) ?? ['out-seller', __('Satıcılar'), null];
            $this->add($this->out, $key, $label, 'party', $s->payment_date, (float) $s->amount * (float) $s->cbar_rate, 1, $url);
            $this->add($this->out, 'fx', __(self::FX), 'fx', $s->payment_date, (float) $s->difference_azn, 9);
        }
        // the logistics company: what left the account, at CBAR of the day
        $logTx = BankTransaction::whereIn('id', $logistics->pluck('transaction_id')->filter())->get()->keyBy('id');
        foreach ($logistics as $l) {
            [$key, $label, $url] = $party($l->act?->counterparty) ?? ['out-logistics', __('Logistika'), null];
            $azn = ($tx = $logTx[$l->transaction_id] ?? null) ? (float) $tx->cbar_amount_azn : (float) $l->amount * (float) $l->cbar_rate;
            $this->add($this->out, $key, $label, 'party', $l->payment_date, $azn, 1, $url);
        }
        // expenses (bank or cash): the bank's fees on their own lines, others by counterparty or category
        foreach ($expenses as $e) {
            $date = $e->paid_at ?? $e->expense_date;
            $azn = (float) ($e->amount_azn ?? $e->amount);
            if ($e->category?->name === SupplierPaymentService::FEE_CATEGORY) {
                in_array($e->id, $transferFees, true)
                    ? $this->add($this->out, 'transfer-fees', __(self::TRANSFER_FEES), 'fees', $date, $azn, 10)
                    : $this->add($this->out, 'domestic-fees', __(self::DOMESTIC_FEES), 'fees', $date, $azn, 8);

                continue;
            }
            if ($e->counterparty) {
                [$key, $label, $url] = $party($e->counterparty);
                $this->add($this->out, $key, $label, 'party', $date, $azn, 1, $url);
            } else {
                $this->add($this->out, 'cat-'.($e->category_id ?? 0), $e->category?->name ?? __('Digər xərclər'), 'category', $date, $azn, 2);
            }
        }
        // any other money out (not a seller / logistics payment, not an expense)
        foreach ($txs->where('direction', 'out')->whereNotIn('id', array_merge($handled, $expenseTx)) as $t) {
            [$key, $label, $url] = $party($t->counterparty) ?? ['out-other', __('Digər ödənişlər'), null];
            $this->add($this->out, $key, $label, 'party', $t->transaction_date, (float) $t->cbar_amount_azn, 1, $url);
        }
        // currency bought / sold: the bank against CBAR (+ loss, − gain)
        foreach (CurrencyExchange::when($p, fn ($q) => $q->where('project_id', $p))->get() as $x) {
            $this->add($this->out, 'fx', __(self::FX), 'fx', $x->exchange_date, (float) $x->difference_azn, 9);
        }
    }
}
