<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\BankAudit;
use App\Models\Company;
use App\Support\BankAudit\Reconciler;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The bank audit of the «Bank → Audit» page from the console: a statement (StatementReader::read output, as
 * base64(gzip(json))) is stored as an audit of the account with its IBAN — the page shows it too — and what does not
 * match is printed, short: the bank lines the system lacks, the movements the bank never made, the dates and amounts that
 * differ. --replace drops the account's earlier audits of the same file first; --keep=0 drops this one after printing.
 * Without a payload it reads the audits already uploaded on the page (the latest of each account) — no statement leaves the server.
 */
class BankAuditCommand extends Command
{
    protected $signature = 'glaust:bank-audit {company : id} {payload? : without it — the latest audit of every account, as uploaded on the page} {--replace} {--keep=1} {--limit=60} {--skip=0} {--account= : the currency or IBAN of the audits to read} {--status= : only these statuses, comma separated}';

    protected $description = 'Bank çıxarışını sistemlə tutuşdurur (Bank → Audit) və uyğunsuzluqları çap edir';

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));
        if ($company && $this->argument('payload') === null) {
            return app(Tenant::class)->runAs($company, function () {
                $want = (string) $this->option('account');
                $latest = BankAudit::with('account')->orderByDesc('id')->get()->unique('bank_account_id')
                    ->filter(fn (BankAudit $a) => $want === '' || $a->currency === $want || $a->iban === $want);
                $latest->isEmpty() && $this->line('Audit yoxdur: çıxarışları Bank → Audit səhifəsində yükləyin.');
                $latest->each(fn (BankAudit $a) => $this->report($a));

                return self::SUCCESS;
            });
        }
        $json = @gzdecode((string) base64_decode((string) $this->argument('payload'), true));
        $s = $json === false ? null : json_decode($json, true);
        if (! $company || ! is_array($s['lines'] ?? null)) {
            $this->error('Şirkət və ya çıxarış oxunmadı.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($s) {
            $account = BankAccount::where('iban', $s['iban'])->first();
            if (! $account || $account->currency !== $s['currency']) {
                $this->error("IBAN {$s['iban']} ({$s['currency']}) hesabı tapılmadı.");

                return self::FAILURE;
            }
            $file = (string) ($s['file_name'] ?? 'console');
            $audit = DB::transaction(function () use ($s, $account, $file) {
                if ($this->option('replace')) {
                    BankAudit::where('bank_account_id', $account->id)->where('file_name', $file)->get()->each->delete();
                }
                $audit = BankAudit::create(['bank_account_id' => $account->id, 'file_name' => $file, 'iban' => $s['iban'], 'currency' => $s['currency'],
                    'period_from' => $s['from'], 'period_to' => $s['to'], 'opening' => $s['opening'], 'closing' => $s['closing'], 'created_by' => null]);
                foreach ($s['lines'] as $l) {
                    $audit->lines()->create(['line_no' => $l['no'], 'line_date' => $l['date'], 'amount' => $l['amount'], 'amount_azn' => $l['azn'],
                        'balance' => $l['balance'], 'description' => $l['description']]);
                }

                return $audit;
            });

            $this->report($audit);
            if (! (int) $this->option('keep')) {
                $audit->delete();
            }

            return self::SUCCESS;
        });
    }

    private function report(BankAudit $audit): void
    {
        $account = $audit->account;
        $r = (new Reconciler($audit))->run();
        $n = fn ($v) => number_format((float) $v, 2, '.', ' ');
        $b = $r['balances'];
        $this->info("#{$audit->id} {$account->bank_name} · {$account->name} {$account->currency}: ".json_encode($r['counts']));
        $this->line('qalıq: açılış bank '.$n($b['opening']['bank']).' / sistem '.$n($b['opening']['system']).' · bağlanış bank '.$n($b['closing']['bank']).' / sistem '.$n($b['closing']['system'])
            .($b['split'] ? ' · ilk fərq '.$b['split']['date'].' bank '.$n($b['split']['bank']).' / sistem '.$n($b['split']['system']) : ''));
        $shown = 0;
        $statuses = array_filter(explode(',', (string) $this->option('status')));
        $skip = (int) $this->option('skip');
        foreach ($r['rows'] as $row) {
            if (in_array($row['status'], Reconciler::MATCHED, true) || ($statuses && ! in_array($row['status'], $statuses, true)) || $skip-- > 0) {
                continue;
            }
            if (++$shown > (int) $this->option('limit')) {
                $this->line('…');
                break;
            }
            $ls = implode(' + ', array_map(fn ($l) => $l['date'].' '.$n($l['amount']).' '.mb_substr(preg_replace('/\s+/', ' ', (string) $l['m']->description), 0, 44), $row['lines']));
            $ts = implode(' + ', array_map(fn ($t) => '#'.$t['id'].' '.$t['date'].' '.$n($t['amount']).' '.mb_substr($r['sources'][$t['id']]['label'] ?? ($t['m']->purpose ?? $t['m']->kind), 0, 40), $row['txs']));
            $this->line($row['status'].' | B: '.($ls ?: '—').' | S: '.($ts ?: '—'));
        }
    }
}
