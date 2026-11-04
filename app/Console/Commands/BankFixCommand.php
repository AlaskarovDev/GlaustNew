<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Company;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\SupplierPayment;
use App\Services\BankAuditFixer;
use App\Services\BankLedger;
use App\Services\CurrencyExchangeService;
use App\Services\ExpenseService;
use App\Services\SupplierPaymentService;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the bank side of a company to its statements (what glaust:bank-audit found), through the real services, in one
 * transaction — all or nothing. payload = base64(gzip(json {ops: [...]})):
 *   {type: account, name, bank_name, currency}                       — an account, created when missing
 *   {type: exchange, date, direction, currency, amount, counter, from, to, notes?}  — a conversion at the bank's figures
 *   {type: redo, tx, amount?, counter?, date?}                        — a conversion (by a leg) booked again with the bank's figures,
 *                                                                        keeping its accounts, Trade and project
 *   {type: date, tx, date}                                             — a movement (and what it belongs to) to the bank's day
 *   {type: pay_amount, tx, amount}                                     — a seller / logistics payment in its own currency: the bank's amount
 *   {type: fees, replace: [tx], fee_of?: [payment tx], new: [{date, account, amount, description, ref?, category?}]}
 *                                                                      — fee expenses booked as the bank charged them; the payments of the
 *                                                                        replaced fees move to the new ones (by «ref»: the transferred total)
 *   {type: expense, date, account, amount, category, pl_line?, description}  — an expense paid from the account
 *   {type: act, act}                                                   — prints a logistics act's payments
 *   {type: sync_fees}                                                  — every seller / logistics payment's own fee fields (what the
 *                                                                        Trade reports read: AJ, BB) from the fee expense it points to,
 *                                                                        shared among its payments by their amounts
 * --dry prints what would be done and rolls everything back.
 */
class BankFixCommand extends Command
{
    protected $signature = 'glaust:bank-fix {company : id} {payload} {--dry}';

    protected $description = 'Bank hərəkətlərini çıxarışa uyğunlaşdırır (konvertasiya, komissiya, tarix, məbləğ)';

    public function handle(BankAuditFixer $fixer, CurrencyExchangeService $exchanges, ExpenseService $expenses, BankLedger $ledger): int
    {
        $company = Company::find($this->argument('company'));
        $json = @gzdecode((string) base64_decode((string) $this->argument('payload'), true));
        $ops = $json === false ? null : (json_decode($json, true)['ops'] ?? null);
        if (! $company || ! is_array($ops)) {
            $this->error('Şirkət və ya məlumat oxunmadı.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($ops, $fixer, $exchanges, $expenses, $ledger) {
            $account = fn (string $name) => BankAccount::where('name', $name)->firstOrFail();
            $tx = fn ($id) => BankTransaction::findOrFail((int) $id);
            $done = 0;
            try {
                DB::transaction(function () use ($ops, $fixer, $exchanges, $expenses, $ledger, $account, $tx, &$done) {
                    foreach ($ops as $i => $op) {
                        $this->apply($op, $fixer, $exchanges, $expenses, $ledger, $account, $tx);
                        $done++;
                    }
                    if ($this->option('dry')) {
                        throw new \RuntimeException('dry');
                    }
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'dry') {
                    $this->error('Əməliyyat '.($done + 1).': '.$e->getMessage().' — heç nə yazılmadı.');

                    return self::FAILURE;
                }
                $this->info("Yoxlama: {$done} əməliyyat keçdi, heç nə yazılmadı.");

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error('Əməliyyat '.($done + 1).': '.class_basename($e).': '.$e->getMessage().' — heç nə yazılmadı.');

                return self::FAILURE;
            }
            $this->info("Yazıldı: {$done} əməliyyat.");

            return self::SUCCESS;
        });
    }

    private function apply(array $op, BankAuditFixer $fixer, CurrencyExchangeService $exchanges, ExpenseService $expenses, BankLedger $ledger, \Closure $account, \Closure $tx): void
    {
        $n = fn ($v) => number_format((float) $v, 2, '.', ' ');
        switch ($op['type']) {
            case 'account':
                BankAccount::firstOrCreate(['name' => $op['name']], ['bank_name' => $op['bank_name'], 'currency' => $op['currency'], 'opening_balance' => 0, 'is_active' => true]);
                $this->line("hesab: {$op['name']} {$op['currency']}");
                break;

            case 'exchange':
                $x = $exchanges->execute(['exchange_date' => $op['date'], 'direction' => $op['direction'], 'currency' => $op['currency'], 'counter_currency' => 'AZN',
                    'amount' => $op['amount'], 'bank_rate' => (float) $op['counter'] / (float) $op['amount'],
                    'from_account_id' => $account($op['from'])->id, 'to_account_id' => $account($op['to'])->id, 'notes' => $op['notes'] ?? null, 'reference' => 'Bank çıxarışı']);
                $this->line("konvertasiya {$op['date']} {$op['direction']} {$n($x->amount)} {$x->currency} = {$n($x->counter_amount)} AZN (CBAR fərqi {$n($x->difference_azn)})");
                break;

            case 'redo':
                $t = $tx($op['tx']);
                $x = CurrencyExchange::where('out_transaction_id', $t->id)->orWhere('in_transaction_id', $t->id)->firstOrFail();
                $d = ['exchange_date' => $op['date'] ?? $x->exchange_date->toDateString(), 'direction' => $x->direction, 'currency' => $x->currency, 'counter_currency' => $x->counter_currency,
                    'amount' => $op['amount'] ?? (float) $x->amount, 'from_account_id' => $x->from_account_id, 'to_account_id' => $x->to_account_id,
                    'notes' => $x->notes, 'reference' => $x->reference, 'project_id' => $x->project_id, 'deal_id' => $x->deal_id];
                $d['bank_rate'] = (float) ($op['counter'] ?? $x->counter_amount) / (float) $d['amount'];
                $old = "{$n($x->amount)} {$x->currency} = {$n($x->counter_amount)}";
                $exchanges->delete($x);
                $y = $exchanges->execute($d);
                $this->line("konvertasiya #{$op['tx']} {$d['exchange_date']}: {$old} → {$n($y->amount)} {$y->currency} = {$n($y->counter_amount)} (CBAR fərqi {$n($y->difference_azn)})");
                break;

            case 'date':
                $t = $tx($op['tx']);
                $from = $t->transaction_date->toDateString();
                $fixer->date($t, $op['date']);
                $this->line("tarix #{$t->id}: {$from} → {$op['date']}");
                break;

            case 'pay_amount':
                $t = $tx($op['tx']);
                $amount = round((float) $op['amount'], 2);
                if ($p = SupplierPayment::where('transaction_id', $t->id)->first()) {
                    if ($p->currency !== $p->account_currency) {
                        throw new \RuntimeException("#{$t->id}: ödəniş və hesab valyutası fərqlidir");
                    }
                    $old = $p->amount;
                    $p->update(['amount' => $amount, 'account_amount' => $amount, 'account_amount_cbar' => $amount, 'difference' => 0, 'difference_azn' => 0]);
                } elseif ($p = LogisticsPayment::with('act')->where('transaction_id', $t->id)->first()) {
                    if ($p->currency !== $p->act->currency) {
                        throw new \RuntimeException("#{$t->id}: ödəniş və akt valyutası fərqlidir");
                    }
                    $old = $p->amount;
                    $p->update(['act_amount' => $amount, 'amount' => $amount, 'amount_cbar' => $amount, 'difference' => 0, 'difference_azn' => 0]);
                } else {
                    throw new \RuntimeException("#{$t->id}: satıcı / logistika ödənişi deyil");
                }
                $ledger->update($t, ['transaction_date' => $t->transaction_date->toDateString(), 'amount' => $amount]);
                $this->line("ödəniş #{$t->id}: {$n($old)} → {$n($amount)}");
                break;

            case 'fees':
                // the payments behind the fees replaced
                $olds = collect($op['replace'] ?? [])->map(fn ($id) => Expense::where('bank_transaction_id', (int) $id)->firstOrFail());
                $pays = collect();
                // or by the payments themselves (their fee, if any, is replaced)
                foreach ($op['fee_of'] ?? [] as $id) {
                    $p = SupplierPayment::where('transaction_id', (int) $id)->first() ?? LogisticsPayment::where('transaction_id', (int) $id)->firstOrFail();
                    $p->fee_expense_id && ! $olds->contains('id', $p->fee_expense_id) && $olds->push(Expense::findOrFail($p->fee_expense_id));
                    $pays->push($p);
                }
                foreach ($olds as $e) {
                    $pays = $pays->merge(SupplierPayment::where('fee_expense_id', $e->id)->get())->merge(LogisticsPayment::where('fee_expense_id', $e->id)->get())
                        ->unique(fn ($p) => $p::class.$p->id);
                }
                $left = $pays->values()->all();
                $made = [];
                foreach ($op['new'] as $f) {
                    $acc = $account($f['account']);
                    $cat = Category::firstOrCreate(['scope' => 'expense', 'name' => $f['category'] ?? SupplierPaymentService::FEE_CATEGORY], ['color' => '#64748b', 'pl_line' => $f['pl_line'] ?? null]);
                    $e = $expenses->save(new Expense, ['expense_date' => $f['date'], 'category_id' => $cat->id, 'description' => mb_substr($f['description'], 0, 255),
                        'amount' => $f['amount'], 'currency' => $acc->currency, 'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => $f['date'],
                        'bank_account_id' => $acc->id, 'reference' => 'Bank çıxarışı']);
                    // the payments it is the fee of: those whose amounts make up the transferred total, else all that are left
                    $mine = isset($f['ref']) ? self::subset($left, (float) $f['ref']) : null;
                    $mine ??= count($op['new']) === 1 ? $left : [];
                    foreach ($mine as $p) {
                        $p->update(['fee_expense_id' => $e->id]);
                    }
                    $left = array_values(array_filter($left, fn ($p) => ! in_array($p, $mine, true)));
                    $made[] = "{$f['date']} {$n($f['amount'])} {$acc->currency} (".count($mine).' ödəniş)';
                }
                if ($left) {
                    throw new \RuntimeException('komissiyası tapılmayan ödəniş qaldı: '.implode(', ', array_map(fn ($p) => $p->payment_date->toDateString().' '.$n($p->amount), $left)));
                }
                foreach ($olds as $e) {
                    $expenses->delete($e);
                }
                $this->line('komissiya: '.$olds->map(fn ($e) => $n($e->amount).' '.$e->currency)->implode(' + ').' → '.implode(' + ', $made));
                break;

            case 'expense':
                $acc = $account($op['account']);
                $cat = Category::firstOrCreate(['scope' => 'expense', 'name' => $op['category']], ['color' => '#64748b', 'pl_line' => $op['pl_line'] ?? null]);
                $e = $expenses->save(new Expense, ['expense_date' => $op['date'], 'category_id' => $cat->id, 'description' => mb_substr($op['description'], 0, 255),
                    'amount' => $op['amount'], 'currency' => $acc->currency, 'status' => 'paid', 'payment_method' => 'bank', 'paid_at' => $op['date'],
                    'bank_account_id' => $acc->id, 'reference' => 'Bank çıxarışı']);
                $this->line("xərc {$op['date']} {$cat->name}: {$n($e->amount)} {$e->currency} = {$n($e->amount_azn)} AZN");
                break;

            case 'sync_fees':
                $this->syncFees();
                break;

            case 'act':
                $act = LogisticsAct::with('payments.transaction', 'deal')->where('act_number', $op['act'])->firstOrFail();
                $this->line("akt {$act->act_number} ({$act->deal?->code}) {$n($act->amount)} {$act->currency}: ".$act->payments->map(fn ($p) => '#'.$p->transaction_id.' '.$p->payment_date->toDateString()
                    .' '.$n($p->amount).' '.$p->currency.' hesab '.$p->bank_account_id)->implode('; '));
                break;

            default:
                throw new \RuntimeException("naməlum əməliyyat: {$op['type']}");
        }
    }

    private function syncFees(): void
    {
        $rates = app(\App\Services\Cbar\CurrencyRates::class);
        $n = fn ($v) => number_format((float) $v, 2, '.', ' ');
        $before = $after = 0.0;
        $changed = 0;
        $ids = SupplierPayment::whereNotNull('fee_expense_id')->pluck('fee_expense_id')->merge(LogisticsPayment::whereNotNull('fee_expense_id')->pluck('fee_expense_id'))->unique();
        foreach (Expense::whereKey($ids)->get() as $e) {
            $pays = SupplierPayment::where('fee_expense_id', $e->id)->get()->concat(LogisticsPayment::where('fee_expense_id', $e->id)->get())->values();
            $w = $pays->map(fn ($p) => (float) $p->amount * (float) $p->cbar_rate)->all();
            $W = array_sum($w) ?: 1;
            $leftAzn = (float) $e->amount_azn;
            $leftAcc = (float) $e->amount;
            foreach ($pays as $i => $p) {
                $last = $i === $pays->count() - 1;
                $azn = $last ? round($leftAzn, 2) : round((float) $e->amount_azn * $w[$i] / $W, 2);
                $acc = $last ? round($leftAcc, 2) : round((float) $e->amount * $w[$i] / $W, 2);
                $leftAzn -= $azn;
                $leftAcc -= $acc;
                $r = (float) $p->cbar_rate;
                $fee = $e->currency === $p->currency ? $acc : round($azn / $r, 2);
                $logistics = $p instanceof LogisticsPayment;
                $before += $logistics ? (float) $p->fee_azn : (float) $p->fee_amount * $r;
                $after += $azn;
                $p->fill(['fee_amount' => $fee, 'fee_account_id' => $e->bank_account_id, 'fee_account_currency' => $e->currency, 'fee_account_amount' => $acc,
                    'fee_bank_rate' => $fee ? $acc / $fee : null, 'fee_difference_azn' => round($azn - $fee * $r, 2)]);
                if ($logistics) {
                    $p->fill(['fee_azn' => $azn, 'fee_eur' => round($azn / $rates->rate('EUR', $p->payment_date->toDateString()), 2), 'fee_included' => false]);
                }
                if ($p->isDirty()) {
                    $p->save();
                    $changed++;
                }
            }
        }
        $this->line("ödənişlərin komissiyası: {$changed} ödəniş yeniləndi · cəmi {$n($before)} → {$n($after)} AZN");
    }

    /** The payments whose own amounts add up to $total (within 1), the fewest first; null when none do. */
    private static function subset(array $pays, float $total): ?array
    {
        $count = count($pays);
        for ($size = 1; $size <= min($count, 6); $size++) {
            foreach (self::combinations(range(0, $count - 1), $size) as $idx) {
                $sum = array_sum(array_map(fn ($i) => (float) ($pays[$i] instanceof LogisticsPayment ? $pays[$i]->act_amount : $pays[$i]->amount), $idx));
                if (abs($sum - $total) <= 1) {
                    return array_map(fn ($i) => $pays[$i], $idx);
                }
            }
        }

        return null;
    }

    private static function combinations(array $items, int $size): \Generator
    {
        if ($size === 0) {
            yield [];

            return;
        }
        foreach ($items as $k => $item) {
            foreach (self::combinations(array_slice($items, $k + 1), $size - 1) as $rest) {
                yield [$item, ...$rest];
            }
        }
    }
}
