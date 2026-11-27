<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Services\BankLedger;
use App\Services\CurrencyExchangeService;
use App\Services\DealRemover;
use App\Services\ExpenseService;
use App\Services\LogisticsService;
use App\Services\NumberGenerator;
use App\Services\SupplierPaymentService;
use App\Support\Invoices\ManualInvoice;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a company's year from its bank statements, Trades included, through the real services — in one transaction, all
 * or nothing. payload = base64(gzip(json)):
 *   accounts        [{key, name, bank_name, iban?, currency}]               — found by IBAN, else by name; created when missing
 *   categories      {name: [scope, pl_line|null]}                            — created when missing; an existing one without a line gets it
 *   counterparties  [{key, name, type, country?}]                            — found by name; created when missing
 *   contracts       [{key, number, date, party, kind, subject, currency}]    — found by number; created when missing
 *   project         {code, name, currency, start, end}                       — found by code; created when missing
 *   trades          [{key, title, date, currency, sale_currency, supplier, buyer, purchase_contract?, sale_contract?,
 *                     invoice: {number, date, amount}, final: {number?, date, amount, currency},
 *                     receipts: [{date, account, amount, purpose}],
 *                     payments: [{date, account, amount, fee?, fee_account?, fee_bank_rate?, reference}],
 *                     logistics: [{party, number, date, act?, act_date?, amount, pay: {date, account, amount, fee?, fee_account?}}]}]
 *   ops             [{type: exchange, date, direction, currency, amount, rate, from, to, trade?, notes?}
 *                    {type: expense, date, account, amount, category, description, pl_line?, trade?}
 *                    {type: transfer, date, from, to, amount, amount_in?, description}
 *                    {type: income, date, account, amount, category, description}]
 *   expect          {account key: closing balance}                           — compared with the system after booking
 * Everything carries the tag (Trades in their notes, the rest in the reference): a second run is refused, --undo removes it.
 * --dry books, prints the balances against the statements and rolls everything back.
 */
class TradeBookCommand extends Command
{
    protected $signature = 'glaust:trade-book {company : id} {payload?} {--tag=trade} {--undo} {--dry}';

    protected $description = 'Bank çıxarışlarından ili Trade-lərlə birlikdə sistemə yazır (yoxlama, geri alma)';

    private array $acc = [];

    private array $party = [];

    private array $contract = [];

    private array $deal = [];

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));
        $tag = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->option('tag')) ?: 'trade';
        if (! $company) {
            $this->error('Şirkət tapılmadı.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($tag) {
            $user = User::forTenant()->orderBy('id')->first();
            $user && auth()->setUser($user);

            if ($this->option('undo')) {
                return $this->undo($tag);
            }

            $json = @gzdecode((string) base64_decode((string) $this->argument('payload'), true));
            $data = $json === false ? null : json_decode($json, true);
            if (! is_array($data)) {
                $this->error('Məlumat oxunmadı.');

                return self::FAILURE;
            }
            if ($this->booked($tag)) {
                $this->error("«{$tag}» artıq yazılıb — əvvəlcə --undo.");

                return self::FAILURE;
            }
            $this->line('Mövcud: '.BankAccount::count().' hesab, '.Deal::count().' Trade, '.BankTransaction::count().' bank hərəkəti.');

            DB::beginTransaction();
            try {
                $this->book($data, $tag, $user?->id ?? 0);
            } catch (ValidationException $e) {
                DB::rollBack();
                $this->error('XƏTA: '.collect($e->errors())->flatten()->first());

                return self::FAILURE;
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error('XƏTA: '.$e->getMessage());

                return self::FAILURE;
            }

            $ok = $this->compare($data['expect'] ?? []);
            if ($this->option('dry')) {
                DB::rollBack();
                $this->info('Yoxlama bitdi — heç nə yazılmadı.'.($ok ? ' Qalıqlar çıxarışlarla eynidir.' : ''));

                return self::SUCCESS;
            }
            DB::commit();
            $this->info('Yazıldı.');

            return self::SUCCESS;
        });
    }

    private function book(array $data, string $tag, int $userId): void
    {
        $n = 0;
        $ref = function () use ($tag, &$n) {
            return $tag.':'.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT);
        };
        $ledger = app(BankLedger::class);

        foreach ($data['accounts'] ?? [] as $a) {
            $acc = (! empty($a['iban']) ? BankAccount::where('iban', $a['iban'])->first() : null) ?? BankAccount::where('name', $a['name'])->first();
            if ($acc && $acc->currency !== $a['currency']) {
                throw new \RuntimeException("{$a['name']}: hesab {$acc->currency}, çıxarış {$a['currency']}");
            }
            $acc ??= BankAccount::create(['name' => $a['name'], 'bank_name' => $a['bank_name'], 'iban' => $a['iban'] ?? null, 'currency' => $a['currency'],
                'opening_balance' => 0, 'opening_date' => $a['opening_date'] ?? null, 'is_active' => true]);
            $this->acc[$a['key']] = $acc;
        }
        foreach ($data['categories'] ?? [] as $name => [$scope, $line]) {
            $c = Category::firstOrCreate(['scope' => $scope, 'name' => $name], ['color' => '#64748b', 'pl_line' => $line]);
            if ($line && ! $c->pl_line) {
                $c->update(['pl_line' => $line]);
            }
        }
        foreach ($data['counterparties'] ?? [] as $p) {
            $this->party[$p['key']] = Counterparty::firstOrCreate(['name' => $p['name']], ['type' => $p['type'], 'entity_type' => 'legal', 'country' => $p['country'] ?? 'Azərbaycan']);
        }
        foreach ($data['contracts'] ?? [] as $c) {
            $this->contract[$c['key']] = Contract::firstOrCreate(['number' => $c['number']], ['contract_date' => $c['date'], 'counterparty_id' => $this->party[$c['party']]->id,
                'kind' => $c['kind'], 'subject' => $c['subject'], 'amount' => 0, 'currency' => $c['currency'], 'status' => 'active']);
        }
        $project = null;
        if ($p = $data['project'] ?? null) {
            $project = Project::firstOrCreate(['code' => $p['code']], ['name' => $p['name'], 'status' => 'active', 'priority' => 'medium', 'currency' => $p['currency'],
                'start_date' => $p['start'] ?? null, 'end_date' => $p['end'] ?? null]);
        }

        foreach ($data['trades'] ?? [] as $t) {
            $deal = Deal::create([
                'project_id' => $project->id, 'code' => app(NumberGenerator::class)->next('deal'), 'title' => $t['title'], 'deal_date' => $t['date'],
                'currency' => $t['currency'], 'sale_currency' => $t['sale_currency'], 'status' => 'active',
                'supplier_id' => $this->party[$t['supplier']]->id, 'purchase_contract_id' => isset($t['purchase_contract']) ? $this->contract[$t['purchase_contract']]->id : null,
                'counterparty_id' => $this->party[$t['buyer']]->id, 'sale_contract_id' => isset($t['sale_contract']) ? $this->contract[$t['sale_contract']]->id : null,
                'notes' => "trade-book:{$tag}",
            ]);
            $this->deal[$t['key']] = $deal;
            $inv = app(ManualInvoice::class)->create($deal, ['number' => $t['invoice']['number'], 'invoice_date' => $t['invoice']['date'],
                'currency' => $t['currency'], 'amount' => (float) $t['invoice']['amount']], $userId);
            $inv->update(['final_amount' => round((float) $t['final']['amount'], 2), 'final_currency' => $t['final']['currency'],
                'sale_number' => $t['final']['number'] ?? null, 'sale_date' => $t['final']['date'], 'status' => 'confirmed']);

            foreach ($t['receipts'] ?? [] as $r) {
                $ledger->record($this->acc[$r['account']], ['direction' => 'in', 'transaction_date' => $r['date'], 'amount' => round((float) $r['amount'], 2),
                    'counterparty_id' => $deal->counterparty_id, 'contract_id' => $deal->sale_contract_id, 'project_id' => $deal->project_id, 'deal_id' => $deal->id,
                    'purpose' => mb_substr($r['purpose'], 0, 255), 'reference' => $ref()]);
            }
            foreach ($t['payments'] ?? [] as $p) {
                app(SupplierPaymentService::class)->pay($deal, ['payment_date' => $p['date'], 'currency' => $t['currency'], 'amount' => (float) $p['amount'],
                    'bank_account_id' => $this->acc[$p['account']]->id, 'fee_amount' => isset($p['fee']) ? round((float) $p['fee'], 2) : 0.0,
                    'fee_account_id' => $this->acc[$p['fee_account'] ?? $p['account']]->id, 'fee_bank_rate' => $p['fee_bank_rate'] ?? null,
                    'reference' => mb_substr($p['reference'], 0, 190)]);
            }
            foreach ($t['logistics'] ?? [] as $l) {
                $pay = $l['pay'];
                $part = ['amount' => (float) $pay['amount'], 'currency' => $t['currency'], 'bank_account_id' => $this->acc[$pay['account']]->id, 'payment_date' => $pay['date'],
                    'fee_amount' => isset($pay['fee']) ? round((float) $pay['fee'], 2) : 0.0];
                if (isset($pay['fee_account'])) {
                    $part['fee_account_id'] = $this->acc[$pay['fee_account']]->id;
                }
                app(LogisticsService::class)->createAct($deal, [
                    'counterparty_id' => $this->party[$l['party']]->id, 'invoice_id' => $inv->id,
                    'logistics_invoice_number' => mb_substr($l['number'], 0, 190), 'logistics_invoice_date' => $l['date'],
                    'act_number' => $l['act'] ?? null, 'act_date' => $l['act_date'] ?? $l['date'],
                    'currency' => $t['currency'], 'amount' => (float) $l['amount'], 'payment_plan' => 'invoice',
                ], [$part], $pay['date']);
            }
            $deal->update(['status' => 'completed']);
            $this->line("Trade {$deal->code}: {$t['title']}");
        }

        $category = fn (string $name, string $scope) => Category::firstOrCreate(['scope' => $scope, 'name' => $name], ['color' => '#64748b']);
        $done = ['exchange' => 0, 'expense' => 0, 'transfer' => 0, 'income' => 0];
        foreach ($data['ops'] ?? [] as $op) {
            $deal = isset($op['trade']) ? $this->deal[$op['trade']] : null;
            match ($op['type']) {
                'exchange' => app(CurrencyExchangeService::class)->execute(['exchange_date' => $op['date'], 'direction' => $op['direction'], 'currency' => $op['currency'],
                    'counter_currency' => 'AZN', 'amount' => (float) $op['amount'], 'bank_rate' => (float) $op['rate'],
                    'from_account_id' => $this->acc[$op['from']]->id, 'to_account_id' => $this->acc[$op['to']]->id,
                    'project_id' => $deal?->project_id, 'deal_id' => $deal?->id, 'notes' => $op['notes'] ?? null, 'reference' => $ref()]),
                'expense' => app(ExpenseService::class)->save(new Expense, [
                    'expense_date' => $op['date'], 'category_id' => $category($op['category'], 'expense')->id, 'description' => mb_substr($op['description'], 0, 255),
                    'amount' => round((float) $op['amount'], 2), 'currency' => $this->acc[$op['account']]->currency, 'status' => 'paid', 'payment_method' => 'bank',
                    'pl_line' => $op['pl_line'] ?? null, 'paid_at' => $op['date'], 'bank_account_id' => $this->acc[$op['account']]->id, 'reference' => $ref(),
                    'project_id' => $deal?->project_id, 'deal_id' => $deal?->id,
                ]),
                'transfer' => $ledger->transfer($this->acc[$op['from']], $this->acc[$op['to']], $op['date'], (float) $op['amount'], (float) ($op['amount_in'] ?? $op['amount']),
                    ['purpose' => mb_substr($op['description'], 0, 255), 'reference' => $ref()]),
                'income' => $ledger->record($this->acc[$op['account']], ['direction' => 'in', 'transaction_date' => $op['date'], 'amount' => round((float) $op['amount'], 2),
                    'purpose' => mb_substr($op['description'], 0, 255), 'reference' => $ref(), 'category_id' => $category($op['category'], 'income')->id]),
            };
            $done[$op['type']]++;
        }
        $this->line(count($this->deal)." Trade, {$done['exchange']} konvertasiya, {$done['expense']} xərc, {$done['transfer']} köçürmə, {$done['income']} daxilolma.");
    }

    /** Each account's balance after booking against its statement's closing balance. */
    private function compare(array $expect): bool
    {
        $ok = true;
        foreach ($this->acc as $key => $acc) {
            $has = $acc->fresh()->balance();
            $want = isset($expect[$key]) ? round((float) $expect[$key], 2) : null;
            $same = $want === null || abs($has - $want) < 0.005;
            $ok = $ok && $same;
            $this->line(sprintf('%s %s · %s: sistem %s%s', $same ? '✓' : '✗', $acc->bank_name, $acc->name, number_format($has, 2, '.', ' '),
                $want === null ? '' : ' / çıxarış '.number_format($want, 2, '.', ' ')));
        }

        return $ok;
    }

    private function booked(string $tag): bool
    {
        return Deal::where('notes', "trade-book:{$tag}")->exists() || BankTransaction::where('reference', 'like', $tag.':%')->exists()
            || Expense::where('reference', 'like', $tag.':%')->exists() || CurrencyExchange::where('reference', 'like', $tag.':%')->exists();
    }

    private function undo(string $tag): int
    {
        return DB::transaction(function () use ($tag) {
            $deals = Deal::where('notes', "trade-book:{$tag}")->get();
            $deals->each(fn ($d) => app(DealRemover::class)->deleteDeal($d));
            $x = CurrencyExchange::where('reference', 'like', $tag.':%')->get();
            $x->each(fn ($e) => app(CurrencyExchangeService::class)->delete($e));
            $e = Expense::where('reference', 'like', $tag.':%')->get();
            $e->each(fn ($v) => app(ExpenseService::class)->delete($v));
            $t = BankTransaction::where('reference', 'like', $tag.':%')->get();
            $t->each(fn ($v) => BankTransaction::whereKey($v->id)->exists() ? app(BankLedger::class)->delete($v) : null);
            $this->info("Geri alındı: {$deals->count()} Trade, {$x->count()} konvertasiya, {$e->count()} xərc, {$t->count()} bank hərəkəti.");

            return self::SUCCESS;
        });
    }
}
