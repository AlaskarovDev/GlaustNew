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
 */
class BankAuditCommand extends Command
{
    protected $signature = 'glaust:bank-audit {company : id} {payload} {--replace} {--keep=1} {--limit=60}';

    protected $description = 'Bank çıxarışını sistemlə tutuşdurur (Bank → Audit) və uyğunsuzluqları çap edir';

    public function handle(): int
    {
        $company = Company::find($this->argument('company'));
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

            $r = (new Reconciler($audit))->run();
            $n = fn ($v) => number_format((float) $v, 2, '.', ' ');
            $b = $r['balances'];
            $this->info("#{$audit->id} {$account->bank_name} · {$account->name} {$account->currency}: ".json_encode($r['counts']));
            $this->line('qalıq: açılış bank '.$n($b['opening']['bank']).' / sistem '.$n($b['opening']['system']).' · bağlanış bank '.$n($b['closing']['bank']).' / sistem '.$n($b['closing']['system'])
                .($b['split'] ? ' · ilk fərq '.$b['split']['date'].' bank '.$n($b['split']['bank']).' / sistem '.$n($b['split']['system']) : ''));
            $shown = 0;
            foreach ($r['rows'] as $row) {
                if (in_array($row['status'], Reconciler::MATCHED, true)) {
                    continue;
                }
                if (++$shown > (int) $this->option('limit')) {
                    $this->line('…');
                    break;
                }
                $ls = implode(' + ', array_map(fn ($l) => $l['date'].' '.$n($l['amount']).' '.mb_substr(preg_replace('/\s+/', ' ', (string) $l['m']->description), 0, 48), $row['lines']));
                $ts = implode(' + ', array_map(fn ($t) => '#'.$t['id'].' '.$t['date'].' '.$n($t['amount']).' '.mb_substr($r['sources'][$t['id']]['label'] ?? ($t['m']->purpose ?? $t['m']->kind), 0, 40), $row['txs']));
                $this->line($row['status'].' | B: '.($ls ?: '—').' | S: '.($ts ?: '—'));
            }
            if (! (int) $this->option('keep')) {
                $audit->delete();
            }

            return self::SUCCESS;
        });
    }
}
