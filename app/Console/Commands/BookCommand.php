<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Services\BankLedger;
use App\Services\ExpenseService;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Books a list of operations (from a bank statement / the company's workbook) through the real services:
 *   accounts   [{name, bank_name, currency}]           — created when missing
 *   categories {name: [scope, pl_line]}                 — created when missing (an existing one keeps its settings)
 *   ops        [{type: expense, date, amount, category, description, account}
 *               {type: transfer, date, amount, from, to, description}
 *               {type: income, date, amount, account, category, description}]
 * Every record carries «<tag>:<hash of the op>» as its reference: a second run skips what is booked, --undo removes it all.
 * payload = base64(gzip(json)).
 */
class BookCommand extends Command
{
    protected $signature = 'glaust:book {company : id} {payload?} {--tag=book} {--undo} {--dry : only count}';

    protected $description = 'Əməliyyatları (xərc, köçürmə, daxilolma) siyahıdan sistemə yazır; --undo geri alır';

    public function handle(BankLedger $ledger, ExpenseService $expenses): int
    {
        $company = Company::find($this->argument('company'));
        $tag = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->option('tag')) ?: 'book';
        if (! $company) {
            $this->error('Şirkət tapılmadı.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($ledger, $expenses, $tag) {
            if ($this->option('undo')) {
                return DB::transaction(function () use ($ledger, $expenses, $tag) {
                    $e = Expense::where('reference', 'like', $tag.':%')->get();
                    $e->each(fn ($x) => $expenses->delete($x));
                    $t = BankTransaction::where('reference', 'like', $tag.':%')->get();
                    $t->each(fn ($x) => $x->exists && BankTransaction::whereKey($x->id)->exists() ? $ledger->delete($x) : null);
                    $this->info("Geri alındı: {$e->count()} xərc, {$t->count()} bank hərəkəti.");

                    return self::SUCCESS;
                });
            }

            $json = @gzdecode((string) base64_decode((string) $this->argument('payload'), true));
            $data = $json === false ? null : json_decode($json, true);
            if (! is_array($data) || ! is_array($data['ops'] ?? null)) {
                $this->error('Məlumat oxunmadı.');

                return self::FAILURE;
            }

            return DB::transaction(function () use ($data, $ledger, $expenses, $tag) {
                foreach ($this->option('dry') ? [] : $data['accounts'] ?? [] as $a) {
                    BankAccount::firstOrCreate(['name' => $a['name']], ['bank_name' => $a['bank_name'], 'currency' => $a['currency'] ?? 'AZN', 'opening_balance' => 0, 'is_active' => true]);
                }
                foreach ($this->option('dry') ? [] : $data['categories'] ?? [] as $name => [$scope, $line]) {
                    Category::firstOrCreate(['scope' => $scope, 'name' => $name], ['color' => '#64748b', 'pl_line' => $line]);
                }
                $account = fn (string $name) => BankAccount::where('name', $name)->firstOrFail();
                $category = fn (string $name, string $scope = 'expense') => Category::firstOrCreate(['scope' => $scope, 'name' => $name], ['color' => '#64748b']);
                $done = ['expense' => 0, 'transfer' => 0, 'income' => 0, 'skipped' => 0];
                foreach ($data['ops'] as $op) {
                    $ref = $tag.':'.substr(md5(json_encode($op)), 0, 16);
                    if (Expense::where('reference', $ref)->exists() || BankTransaction::where('reference', $ref)->exists()) {
                        $done['skipped']++;

                        continue;
                    }
                    if ($this->option('dry')) {
                        $done[$op['type']]++;

                        continue;
                    }
                    match ($op['type']) {
                        'expense' => $expenses->save(new Expense, [
                            'expense_date' => $op['date'], 'category_id' => $category($op['category'])->id, 'description' => mb_substr($op['description'], 0, 255),
                            'amount' => $op['amount'], 'currency' => $account($op['account'])->currency, 'status' => 'paid', 'payment_method' => 'bank',
                            'paid_at' => $op['date'], 'bank_account_id' => $account($op['account'])->id, 'reference' => $ref,
                        ]),
                        'transfer' => $ledger->transfer($account($op['from']), $account($op['to']), $op['date'], $op['amount'], $op['amount'],
                            ['purpose' => mb_substr($op['description'], 0, 255), 'reference' => $ref]),
                        'income' => $ledger->record($account($op['account']), ['direction' => 'in', 'transaction_date' => $op['date'], 'amount' => $op['amount'],
                            'purpose' => mb_substr($op['description'], 0, 255), 'reference' => $ref, 'category_id' => $category($op['category'], 'income')->id]),
                    };
                    $done[$op['type']]++;
                }
                $this->info(($this->option('dry') ? 'Yoxlama: ' : 'Yazıldı: ')."{$done['expense']} xərc, {$done['transfer']} köçürmə, {$done['income']} daxilolma; artıq var: {$done['skipped']}.");

                return self::SUCCESS;
            });
        });
    }
}
