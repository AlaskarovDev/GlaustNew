<?php

namespace Tests\Feature\Concerns;

use App\Models\BankAccount;
use App\Models\CurrencyExchange;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\LogisticsAct;
use App\Models\LogisticsPayment;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SupplierPayment;
use App\Services\BankLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** The company's ATF workbook, sheet ATF, row 3 — every input of it recorded in the system. */
trait AtfRow3
{
    protected function rates(string $date, float $eur, float $rub): void
    {
        foreach (['EUR' => $eur, 'RUB' => $rub] as $code => $rate) {
            DB::table('currency_rates')->insert(['currency_code' => $code, 'rate_date' => $date, 'bulletin_date' => $date,
                'rate' => $rate, 'nominal' => 1, 'value' => $rate, 'source' => 'CBAR', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function row3(): array
    {
        $admin = $this->makeCompany();
        Http::fake(fn () => Http::response('', 503));   // only the stored rates count
        $this->rates('2024-12-27', 1.7694, 0.016958);   // K: our invoice   (L, M)
        $this->rates('2024-12-29', 1.7724, 0.016318);   // T: money in      (U, V)
        $this->rates('2025-01-07', 1.7666, 0.015962);   // X: seller paid   (Y, Z)
        $this->rates('2025-01-28', 1.7739, 0.017487);   // BI: act          (BJ, BK)
        $this->rates('2025-02-10', 1.7533, 0.017526);   // AY: logistics paid (AZ)

        $deal = $this->inTenant($admin, function () {
            $project = Project::create(['code' => 'ATF', 'name' => 'ATF', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);
            $deal = Deal::create(['project_id' => $project->id, 'code' => 'TR-1', 'title' => 'Ellis → Axios', 'deal_date' => '2024-12-27', 'currency' => 'EUR', 'status' => 'active']);
            $inv = Invoice::create(['project_id' => $project->id, 'deal_id' => $deal->id, 'type' => 'supplier', 'number' => '916161494', 'invoice_date' => '2024-12-27',
                'currency' => 'EUR', 'total' => 73644.80, 'cbar_rate' => 1.7694, 'total_azn' => 130307.11, 'status' => 'confirmed',
                'fx_source' => 'forecast', 'fx_date' => '2024-12-27', 'fx_base_azn' => 1.79, 'fx_target_azn' => 0.0165, 'fx_rate' => 1.79 / 0.0165]);
            SalesDocument::create(['deal_id' => $deal->id, 'project_id' => $project->id, 'source_invoice_id' => $inv->id, 'kind' => 'proforma', 'number' => 'ATFAXI-1204',
                'doc_date' => '2024-12-27', 'currency' => 'RUB', 'lines' => [['n' => 1, 'description' => 'Goods', 'quantity' => 1, 'unit_price' => 9898036.68, 'total' => 9898036.68]], 'total' => 9898036.68]);
            $rub = BankAccount::create(['name' => 'RUB', 'bank_name' => 'Bank', 'currency' => 'RUB', 'opening_balance' => 0, 'is_active' => true]);
            $eur = BankAccount::create(['name' => 'EUR', 'bank_name' => 'Bank', 'currency' => 'EUR', 'opening_balance' => 0, 'is_active' => true]);
            $azn = BankAccount::create(['name' => 'AZN', 'bank_name' => 'Bank', 'currency' => 'AZN', 'opening_balance' => 0, 'is_active' => true]);
            app(BankLedger::class)->record($rub, ['direction' => 'in', 'transaction_date' => '2024-12-29', 'amount' => 9898036.68, 'deal_id' => $deal->id, 'project_id' => $project->id]);
            SupplierPayment::create(['deal_id' => $deal->id, 'payment_date' => '2025-01-07', 'currency' => 'EUR', 'amount' => 73644.80, 'bank_account_id' => $eur->id,
                'account_currency' => 'EUR', 'cbar_rate' => 1.7666, 'cbar_account_rate' => 1.7666, 'cbar_cross' => 1, 'bank_rate' => 1,
                'account_amount_cbar' => 73644.80, 'account_amount' => 73644.80, 'difference' => 0, 'difference_azn' => 0, 'fee_amount' => 184.112, 'fee_account_amount' => 184.11]);
            $x = fn ($dir, $cur, $amount, $cbar, $bank) => CurrencyExchange::create(['exchange_date' => '2025-01-07', 'direction' => $dir, 'currency' => $cur, 'amount' => $amount,
                'counter_currency' => 'AZN', 'cbar_rate' => $cbar, 'cbar_counter_rate' => 1, 'cbar_cross' => $cbar, 'bank_rate' => $bank,
                'counter_amount_cbar' => round($amount * $cbar, 2), 'counter_amount' => round($amount * $bank, 2), 'difference' => 0, 'difference_azn' => 0,
                'from_account_id' => $azn->id, 'to_account_id' => $azn->id, 'project_id' => $deal->project_id, 'deal_id' => $deal->id]);
            $x('sell', 'RUB', 9898036.68, 0.015962, 0.0159);
            $x('buy', 'EUR', 73829, 1.7666, 1.779);
            $act = LogisticsAct::create(['deal_id' => $deal->id, 'invoice_id' => $inv->id, 'logistics_invoice_number' => 'VAM25006583_179', 'logistics_invoice_date' => '2025-01-28',
                'act_number' => 'VAM25006583', 'act_date' => '2025-01-28', 'currency' => 'RUB',
                'amount' => 979732.68, 'cbar_rate' => 0.017487, 'cbar_rub' => 0.017487, 'cbar_eur' => 1.7739,
                'amount_azn' => round(979732.68 * 0.017487, 2), 'amount_rub' => 979732.68, 'amount_eur' => round(979732.68 * 0.017487 / 1.7739, 2)]);
            LogisticsPayment::create(['logistics_act_id' => $act->id, 'deal_id' => $deal->id, 'payment_date' => '2025-02-10', 'act_amount' => 979732.68, 'currency' => 'RUB',
                'cbar_act_rate' => 0.017526, 'cbar_rate' => 0.017526, 'cbar_cross' => 1, 'bank_rate' => 1, 'amount_cbar' => 979732.68, 'amount' => 979732.68,
                'difference' => 0, 'difference_azn' => 0, 'fee_amount' => 2449.33, 'fee_azn' => 42.93, 'bank_account_id' => $rub->id]);

            return $deal;
        });

        return [$admin, $deal];
    }

}
