<?php

namespace App\Console\Commands;

use App\Imports\Atf\AtfImporter;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Brings imported Trades up to rows of an ATF sheet (AtfSheet::parse output): the logistics act and payment a Trade still
 * lacks, through AtfImporter::complete — the same as the import's «Yenilənəcək». The carrier and the accounts are the
 * ones the company's other logistics payments use. payload = base64(gzip(json {rows: [...]})).
 */
class AtfCompleteCommand extends Command
{
    protected $signature = 'glaust:atf-complete {company : id} {payload} {--dry : only say what would be done}';

    protected $description = 'İdxal olunmuş Trade-lərə çatışmayan logistikanı ATF sətirlərindən əlavə edir';

    public function handle(AtfImporter $importer): int
    {
        $company = Company::find($this->argument('company'));
        $json = @gzdecode((string) base64_decode((string) $this->argument('payload'), true));
        $rows = $json === false ? null : (json_decode($json, true)['rows'] ?? null);
        if (! $company || ! is_array($rows)) {
            $this->error('Şirkət və ya məlumat tapılmadı.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($rows, $importer) {
            // the carrier and the accounts the company's logistics payments use
            $carrier = LogisticsAct::whereNotNull('counterparty_id')->selectRaw('counterparty_id, COUNT(*) n')->groupBy('counterparty_id')->orderByDesc('n')->value('counterparty_id');
            $rub = LogisticsPayment::where('currency', 'RUB')->selectRaw('bank_account_id, COUNT(*) n')->groupBy('bank_account_id')->orderByDesc('n')->value('bank_account_id');
            $azn = LogisticsPayment::whereNotNull('fee_account_id')->where('fee_account_currency', 'AZN')->selectRaw('fee_account_id, COUNT(*) n')->groupBy('fee_account_id')->orderByDesc('n')->value('fee_account_id');
            if (! $rub || ! $azn) {
                $this->error('Logistika ödənişlərinin RUB / AZN hesabı tapılmadı.');

                return self::FAILURE;
            }
            $setup = ['logistics_id' => $carrier, 'rub_account' => $rub, 'azn_account' => $azn];
            $failed = 0;
            foreach ($rows as $row) {
                $inv = Invoice::with('deal')->where('type', 'supplier')->where('number', (string) $row['seller_no'])->first();
                if (! $inv) {
                    $this->line("{$row['seller_no']}: Trade yoxdur");

                    continue;
                }
                if (! AtfImporter::lacksLogistics($inv, $row)) {
                    $this->line("{$row['seller_no']} {$inv->deal->code}: əlavə ediləcək logistika yoxdur");

                    continue;
                }
                if ($this->option('dry')) {
                    $this->line("{$row['seller_no']} {$inv->deal->code}: əlavə olunacaq — {$row['logistics_paid']} RUB · {$row['logistics_date']} · akt {$row['act_no']} {$row['act_date']}");

                    continue;
                }
                try {
                    $importer->complete($inv, $row, $setup);
                    $this->line("{$row['seller_no']} {$inv->deal->code}: logistika əlavə olundu — {$row['logistics_paid']} RUB · {$row['logistics_date']}");
                } catch (ValidationException $e) {
                    $failed++;
                    $this->line("{$row['seller_no']}: XƏTA — ".collect($e->errors())->flatten()->first());
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }
}
