<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Project;
use App\Models\SalesDocumentRevision;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a Trade with everything that belongs to it, undoing its money on the bank accounts:
 * logistics invoices (with their payments and fees), payments to the seller (with fees), incoming
 * payments, expenses booked on it, buyer documents and seller invoices. Currency exchanges are real
 * money moves of their own: they stay and are only unlinked. A project goes with all its Trades.
 */
class DealRemover
{
    public function __construct(private LogisticsService $logistics, private SupplierPaymentService $supplierPayments, private ExpenseService $expenses) {}

    /** What deleting the Trade will remove (shown before confirming). */
    public function summary(Deal $deal): array
    {
        return [
            'invoices' => $deal->invoices()->count(),
            'documents' => $deal->salesDocuments()->count(),
            'payments' => BankTransaction::where('deal_id', $deal->id)->where('direction', 'in')->count(),
            'supplier_payments' => $deal->supplierPayments()->count(),
            'logistics' => $deal->logisticsActs()->count(),
            'expenses' => Expense::where('deal_id', $deal->id)->count(),
        ];
    }

    public function deleteDeal(Deal $deal): void
    {
        DB::transaction(function () use ($deal) {
            foreach ($deal->logisticsActs()->get() as $act) {
                $this->logistics->deleteAct($act);
            }
            foreach ($deal->supplierPayments()->get() as $p) {
                $this->supplierPayments->delete($p);
            }
            foreach (Expense::where('deal_id', $deal->id)->get() as $e) {
                $this->expenses->delete($e);
            }
            BankTransaction::where('deal_id', $deal->id)->get()->each->delete();   // incoming payments and anything else booked on it
            $deal->currencyExchanges()->update(['deal_id' => null]);
            SalesDocumentRevision::where('deal_id', $deal->id)->delete();
            $deal->salesDocuments()->get()->each->delete();
            foreach ($deal->invoices()->get() as $invoice) {
                $invoice->items()->delete();
                $invoice->delete();
            }
            $deal->delete();
        });
    }

    public function deleteProject(Project $project): void
    {
        DB::transaction(function () use ($project) {
            foreach ($project->deals()->get() as $deal) {
                $this->deleteDeal($deal);
            }
            \App\Models\CurrencyExchange::where('project_id', $project->id)->update(['project_id' => null]);
            $project->delete();
        });
    }
}
