<?php

namespace App\Imports\Atf;

use App\Models\BankAccount;
use App\Models\Deal;
use App\Models\Invoice;
use App\Services\BankLedger;
use App\Services\CurrencyExchangeService;
use App\Services\LogisticsService;
use App\Services\NumberGenerator;
use App\Services\SupplierPaymentService;
use App\Support\Invoices\LogisticsAllocator;
use App\Support\Invoices\ManualInvoice;
use App\Support\Invoices\RubConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Works one ATF row through the system as a user would, step by step, with the same services:
 * Trade → seller invoice (amount only) with the buyer's figure, logistics forecast and forecast rates →
 * money in from the buyer → roubles sold / euros bought → payment to the seller with its fee →
 * the logistics invoice / act and its payment with the fee. All of a row or nothing.
 */
class AtfImporter
{
    public function __construct(
        private NumberGenerator $numbers, private ManualInvoice $manual, private LogisticsAllocator $allocator, private RubConverter $rub,
        private BankLedger $ledger, private CurrencyExchangeService $exchanges, private SupplierPaymentService $suppliers, private LogisticsService $logistics,
    ) {}

    /** The Trade already imported for this seller invoice in the project, if any. */
    public static function existing(int $projectId, string $sellerNo): ?Invoice
    {
        return Invoice::where('project_id', $projectId)->where('type', 'supplier')->where('number', $sellerNo)->first();
    }

    /**
     * @param  array  $s  setup: project_id, supplier_id, buyer_id, logistics_id, purchase_contract_id, sale_contract_id, eur_account, rub_account, azn_account
     * @return array{deal: Deal, steps: list<string>}
     *
     * @throws ValidationException
     */
    public function import(array $row, array $s, int $userId): array
    {
        $eur = BankAccount::findOrFail($s['eur_account']);
        $rubAcc = BankAccount::findOrFail($s['rub_account']);
        $azn = BankAccount::findOrFail($s['azn_account']);
        $steps = [];

        return DB::transaction(function () use ($row, $s, $userId, $eur, $rubAcc, $azn, &$steps) {
            // 1. the Trade
            $deal = Deal::create([
                'project_id' => $s['project_id'], 'code' => $this->numbers->next('deal'),
                'title' => 'ATF #'.($row['order'] ?? $row['line']).': '.$row['seller_no'].' → '.($row['buyer_no'] ?? '—'),
                'deal_date' => $row['seller_date'], 'currency' => 'EUR', 'sale_currency' => 'RUB', 'status' => 'active',
                'supplier_id' => $s['supplier_id'], 'purchase_contract_id' => $s['purchase_contract_id'] ?? null,
                'counterparty_id' => $s['buyer_id'], 'sale_contract_id' => $s['sale_contract_id'] ?? null,
            ]);
            $steps[] = __('Trade :v1', ['v1' => $deal->code]);

            // 2. the seller invoice as an amount, our invoice to the buyer as its final figure
            $inv = $this->manual->create($deal, ['number' => $row['seller_no'], 'invoice_date' => $row['seller_date'], 'currency' => 'EUR', 'amount' => (float) $row['seller_amount']], $userId);
            $inv->update(['final_amount' => round((float) $row['buyer_amount'], 2), 'final_currency' => 'RUB',
                'sale_number' => $row['buyer_no'], 'sale_date' => $row['buyer_date'], 'status' => 'confirmed']);
            if ($row['logistics_forecast']) {
                $this->allocator->apply($inv->fresh(), 'forecast', 'total', 'EUR', (float) $row['logistics_forecast']);
            }
            if ($row['forecast_eur'] && $row['forecast_rub']) {
                $this->rub->apply($inv->fresh(), 'forecast', $row['invoiced_on'] ?? $row['buyer_date'] ?? $row['seller_date'], (float) $row['forecast_eur'], (float) $row['forecast_rub']);
            }
            $steps[] = __('Faktura :v1 → :v2', ['v1' => $row['seller_no'], 'v2' => money($row['buyer_amount'], 'RUB')]);

            // 3. the buyer pays (at CBAR of the day)
            if ($row['money_in_date']) {
                $this->ledger->record($rubAcc, [
                    'direction' => 'in', 'transaction_date' => $row['money_in_date'], 'amount' => round((float) $row['buyer_amount'], 2),
                    'counterparty_id' => $s['buyer_id'], 'contract_id' => $s['sale_contract_id'] ?? null, 'project_id' => $deal->project_id, 'deal_id' => $deal->id,
                    'purpose' => 'Trade '.$deal->code.' üzrə alıcının ödənişi'.($row['buyer_no'] ? ' ('.$row['buyer_no'].')' : ''),
                ]);
                $steps[] = __('Mədaxil');
            }

            // 4. roubles sold, euros bought on the operation day, at the bank's rates
            $opDate = $row['operation_date'] ?? $row['seller_paid_date'];
            if ($opDate && $row['rub_sold'] && $row['bank_rub']) {
                $this->exchanges->execute(['exchange_date' => $opDate, 'direction' => 'sell', 'currency' => 'RUB', 'counter_currency' => 'AZN',
                    'amount' => (float) $row['rub_sold'], 'bank_rate' => (float) $row['bank_rub'], 'from_account_id' => $rubAcc->id, 'to_account_id' => $azn->id,
                    'project_id' => $deal->project_id, 'deal_id' => $deal->id, 'reference' => 'ATF '.$row['seller_no']]);
                $steps[] = __('Rubl satışı');
            }
            if ($opDate && $row['eur_bought'] && $row['bank_eur']) {
                $this->exchanges->execute(['exchange_date' => $opDate, 'direction' => 'buy', 'currency' => 'EUR', 'counter_currency' => 'AZN',
                    'amount' => (float) $row['eur_bought'], 'bank_rate' => (float) $row['bank_eur'], 'from_account_id' => $azn->id, 'to_account_id' => $eur->id,
                    'project_id' => $deal->project_id, 'deal_id' => $deal->id, 'reference' => 'ATF '.$row['seller_no']]);
                $steps[] = __('Avro alışı');
            }

            // 5. the seller is paid, with its bank fee (as typed in the sheet, else by the bank's rule)
            $paidOn = $row['seller_paid_date'] ?? $row['operation_date'];
            if ($paidOn) {
                $this->suppliers->pay($deal, ['payment_date' => $paidOn, 'currency' => 'EUR', 'amount' => (float) $row['seller_amount'], 'bank_account_id' => $eur->id,
                    'fee_amount' => $row['seller_fee'] !== null ? round((float) $row['seller_fee'], 2) : null, 'fee_account_id' => $eur->id,
                    'reference' => 'ATF '.$row['seller_no']]);
                $steps[] = __('Satıcıya ödəniş');
            }

            // 6. logistics: the carrier's invoice (and act), paid in roubles; the fee off the AZN account at the bank's rate
            if ($row['logistics_paid'] && $row['logistics_date']) {
                $part = ['amount' => (float) $row['logistics_paid'], 'currency' => 'RUB', 'bank_account_id' => $rubAcc->id, 'payment_date' => $row['logistics_date']];
                if ($row['logistics_fee_azn'] !== null && $row['logistics_rate']) {
                    $part += ['fee_amount' => round((float) $row['logistics_fee_azn'] / (float) $row['logistics_rate'], 2), 'fee_account_id' => $azn->id, 'fee_bank_rate' => (float) $row['logistics_rate']];
                }
                $this->logistics->createAct($deal, [
                    'counterparty_id' => $s['logistics_id'] ?? null, 'invoice_id' => $inv->id,
                    'logistics_invoice_number' => $row['logistics_no'] ?: 'ATF-'.$row['seller_no'],
                    'logistics_invoice_date' => $row['logistics_inv_date'] ?? $row['logistics_date'],
                    'act_number' => $row['act_no'], 'act_date' => $row['act_date'],
                    'currency' => 'RUB', 'amount' => (float) $row['logistics_paid'], 'payment_plan' => 'invoice',
                ], [$part], $row['logistics_date']);
                $steps[] = __('Logistika');
            }

            $deal->update(['status' => $paidOn && $row['money_in_date'] ? 'completed' : 'invoiced']);

            return ['deal' => $deal, 'steps' => $steps];
        });
    }
}
