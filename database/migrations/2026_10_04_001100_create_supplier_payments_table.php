<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Our payment to the seller of a deal, in the invoice currency (e.g. EUR), possibly from an account
 * in another currency. Kept for the reports: CBAR rates of the date, the bank's rate, the debit at
 * each, the bank-vs-CBAR difference, and the bank's fee (EUR: 0.25 %, at least 25 and at most 300 EUR) which is
 * also booked as an expense. The account debit is one bank movement (transaction_id).
 *
 * bank_rate: 1 unit of `currency` = X units of the account currency (1 when they are the same).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $t->date('payment_date');
            $t->string('currency', 8);                        // what the seller receives
            $t->decimal('amount', 18, 2);
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->string('account_currency', 8);
            $t->decimal('cbar_rate', 18, 8);                  // AZN per 1 currency
            $t->decimal('cbar_account_rate', 18, 8);          // AZN per 1 account currency
            $t->decimal('cbar_cross', 24, 12);                // account currency per 1 currency, CBAR
            $t->decimal('bank_rate', 24, 12);                 // account currency per 1 currency, the bank's
            $t->decimal('account_amount_cbar', 18, 2);
            $t->decimal('account_amount', 18, 2);             // debited for the payment itself
            $t->decimal('difference', 18, 2);                 // account_amount - account_amount_cbar (+ = paid more)
            $t->decimal('difference_azn', 18, 2);
            $t->decimal('fee_percent', 8, 4)->nullable();
            $t->decimal('fee_minimum', 18, 2)->nullable();
            $t->decimal('fee_maximum', 18, 2)->nullable();
            $t->decimal('fee_amount', 18, 2)->default(0);     // in `currency`
            $t->decimal('fee_account_amount', 18, 2)->default(0);
            $t->foreignId('transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $t->foreignId('fee_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->string('purpose')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
