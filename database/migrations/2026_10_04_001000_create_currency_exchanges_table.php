<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valyuta alış-satışı. Every number of the deal is kept for the reports: both CBAR rates of the
 * date (AZN per unit), the CBAR cross rate, the bank's rate, the counter amount at each, and the
 * bank-vs-CBAR difference in the counter currency and in AZN. The money moves as a conversion
 * pair of bank movements (out_transaction / in_transaction).
 *
 * Rates are quoted as 1 unit of `currency` = X units of `counter_currency`.
 *   buy:  we get `amount` of currency, we pay counter_amount (bank)          loss = bank - cbar
 *   sell: we give `amount` of currency, we get counter_amount (bank)         loss = cbar - bank
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_exchanges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('exchange_date');
            $t->string('direction', 4);                    // buy | sell
            $t->string('currency', 8);                     // the currency bought / sold
            $t->decimal('amount', 18, 2);
            $t->string('counter_currency', 8);             // paid with / received in
            $t->decimal('cbar_rate', 18, 8);               // AZN per 1 currency
            $t->decimal('cbar_counter_rate', 18, 8);       // AZN per 1 counter currency
            $t->decimal('cbar_cross', 24, 12);             // counter per 1 currency, CBAR
            $t->decimal('bank_rate', 24, 12);              // counter per 1 currency, the bank's
            $t->decimal('counter_amount_cbar', 18, 2);
            $t->decimal('counter_amount', 18, 2);          // at the bank's rate (what moved)
            $t->decimal('difference', 18, 2);              // loss (+) / gain (-) in the counter currency
            $t->decimal('difference_azn', 18, 2);          // the same at CBAR
            $t->foreignId('from_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $t->foreignId('out_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $t->foreignId('in_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'exchange_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchanges');
    }
};
