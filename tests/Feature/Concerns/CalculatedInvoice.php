<?php

namespace Tests\Feature\Concerns;

use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\Deal;
use App\Models\Project;

/** The company's sample sheet (proforma 221619), calculated through logistics and commission. */
trait CalculatedInvoice
{
    protected const TOTALS = [15648, 35456, 81992, 8864, 17728, 5848, 17920, 3130, 5336];

    protected const QTY = [3200, 3200, 7400, 800, 1600, 400, 800, 200, 800];

    protected const NAMES = ['TD-Weiss Migrastar Gr.1/S/IPA', 'Supra EB Cyan Folie FCM', 'Supra EB Gelb Folie FCM', 'Supra EB Schwarz Folie FCM',
        'Supra EB Magenta Folie FCM', 'Supra EB PANTONE® Transparentweiss', 'Supra EB PANTONE®Reflexblau', 'Supra EB Warmrot', 'HERMA PE weiss tc (852) 62Gpt / 517'];

    /** The company's sheet, calculated up to the RUR columns (logistics 10 200, 3.5%, Proq 2.0005 / 0.0211). */
    protected function calculated(): array
    {
        $this->fakeCbar();
        $admin = $this->makeCompany();
        $inv = $this->inTenant($admin, function () {
            $seller = Counterparty::create(['type' => 'supplier', 'entity_type' => 'legal', 'name' => 'Weiss GmbH', 'country' => 'Almaniya']);
            $buyer = Counterparty::create(['type' => 'customer', 'entity_type' => 'legal', 'name' => 'CCL Kontur LLC', 'country' => 'Russia', 'city' => 'Moscow',
                'address' => 'Domodedovskoye highway 51', 'email' => 'e.dulinov@ccl-kontur.ru']);
            $purchase = Contract::create(['number' => 'P-1', 'contract_date' => today(), 'counterparty_id' => $seller->id, 'kind' => 'purchase', 'subject' => 'X', 'amount' => 1, 'currency' => 'EUR', 'status' => 'active']);
            $sale = Contract::create(['number' => '03CCLRU/010223', 'contract_date' => '2023-02-09', 'counterparty_id' => $buyer->id, 'kind' => 'sale', 'subject' => 'X', 'amount' => 1, 'currency' => 'RUB', 'status' => 'active']);
            $p = Project::create(['code' => 'P', 'name' => 'P', 'status' => 'active', 'priority' => 'medium', 'currency' => 'EUR']);
            $deal = Deal::create(['project_id' => $p->id, 'code' => 'TD-1', 'title' => 'T', 'deal_date' => today(), 'currency' => 'EUR', 'status' => 'invoiced',
                'supplier_id' => $seller->id, 'purchase_contract_id' => $purchase->id, 'counterparty_id' => $buyer->id, 'sale_contract_id' => $sale->id]);
            $inv = $deal->invoices()->create(['project_id' => $p->id, 'type' => 'supplier', 'number' => '221619', 'invoice_date' => today()->subDays(3),
                'counterparty_id' => $seller->id, 'contract_id' => $purchase->id, 'currency' => 'EUR', 'total' => array_sum(self::TOTALS), 'status' => 'draft']);
            foreach (self::TOTALS as $i => $t) {
                $inv->items()->create(['line_no' => $i + 1, 'description' => self::NAMES[$i], 'hs_code' => '32151900', 'quantity' => self::QTY[$i], 'uom' => 'kg',
                    'unit_price' => round($t / self::QTY[$i], 4), 'total' => $t]);
            }

            return $inv;
        });
        $this->actingAs($admin);
        $this->post(route('invoices.logistics', $inv), ['logistics_mode' => 'actual', 'logistics_method' => 'total', 'logistics_amount' => '10200', 'logistics_currency' => 'EUR']);
        $this->post(route('invoices.commission', $inv), ['commission_rate' => '3,5']);

        return [$admin, $inv];
    }

    protected function applyRub($inv)
    {
        return $this->post(route('invoices.rub', $inv), ['fx_source' => 'forecast', 'fx_date' => today()->addDays(5)->toDateString(), 'fx_base_azn' => '2,0005', 'fx_target_azn' => '0,0211']);
    }
}
