<?php

namespace App\Console\Commands;

use App\Imports\Atf\AtfImporter;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CurrencyExchange;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports ATF rows (AtfSheet::parse output) as new Trades, as «Toplu Trade importu» does, with the setup of a Trade
 * already imported (--like=its seller invoice №): its project, seller, buyer, contracts, carrier and the accounts its
 * money went through. A row whose Trade exists (in any project) is skipped. payload = base64(gzip(json {rows: [...]})).
 * --dry imports and rolls everything back, so the run is checked through the real services.
 */
class AtfImportCommand extends Command
{
    protected $signature = 'glaust:atf-import {company : id} {payload} {--like= : seller invoice № of an imported Trade whose setup to use} {--dry}';

    protected $description = 'ATF sətirlərini yeni Trade kimi idxal edir (mövcud Trade-in ayarları ilə)';

    public function handle(AtfImporter $importer): int
    {
        $company = Company::find($this->argument('company'));
        $json = @gzdecode((string) base64_decode((string) $this->argument('payload'), true));
        $rows = $json === false ? null : (json_decode($json, true)['rows'] ?? null);
        if (! $company || ! is_array($rows) || ! $this->option('like')) {
            $this->error('Şirkət, məlumat və ya --like tapılmadı.');

            return self::FAILURE;
        }

        return app(Tenant::class)->runAs($company, function () use ($rows, $importer) {
            $like = Invoice::with('deal.supplierPayments', 'deal.logisticsActs')->where('type', 'supplier')->where('number', (string) $this->option('like'))->first();
            $deal = $like?->deal;
            $in = $deal ? BankTransaction::where('deal_id', $deal->id)->where('direction', 'in')->where('kind', 'regular')->value('bank_account_id') : null;
            $buy = $deal ? CurrencyExchange::where('deal_id', $deal->id)->where('direction', 'buy')->first() : null;
            $setup = $deal ? [
                'project_id' => $deal->project_id, 'supplier_id' => $deal->supplier_id, 'buyer_id' => $deal->counterparty_id,
                'purchase_contract_id' => $deal->purchase_contract_id, 'sale_contract_id' => $deal->sale_contract_id,
                'logistics_id' => $deal->logisticsActs->first()?->counterparty_id,
                'eur_account' => $deal->supplierPayments->first()?->bank_account_id ?? $buy?->to_account_id, 'rub_account' => $in, 'azn_account' => $buy?->from_account_id,
            ] : null;
            if (! $setup || ! $setup['eur_account'] || ! $setup['rub_account'] || ! $setup['azn_account']) {
                $this->error('--like Trade-i və ya onun hesabları tapılmadı.');

                return self::FAILURE;
            }
            $user = User::forTenant()->orderBy('id')->first();
            auth()->setUser($user);

            try {
                return DB::transaction(function () use ($rows, $importer, $setup, $user) {
                    $failed = 0;
                    foreach ($rows as $row) {
                        if ($inv = AtfImporter::existing($setup['project_id'], (string) $row['seller_no'])) {
                            $this->line("{$row['seller_no']}: artıq var — Trade {$inv->deal?->code}");

                            continue;
                        }
                        if ($row['errors'] ?? []) {
                            $failed++;
                            $this->line("{$row['seller_no']}: XƏTA — ".implode('; ', $row['errors']));

                            continue;
                        }
                        try {
                            $res = $importer->import($row, $setup, $user->id);
                            $this->line("{$row['seller_no']}: Trade {$res['deal']->code} — ".implode(' · ', $res['steps']));
                        } catch (ValidationException $e) {
                            $failed++;
                            $this->line("{$row['seller_no']}: XƏTA — ".collect($e->errors())->flatten()->first());
                        }
                    }
                    if ($this->option('dry')) {
                        throw new \RuntimeException('dry');
                    }

                    return $failed ? self::FAILURE : self::SUCCESS;
                });
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'dry') {
                    throw $e;
                }
                $this->info('Yoxlama: heç nə yazılmadı.');

                return self::SUCCESS;
            }
        });
    }
}
