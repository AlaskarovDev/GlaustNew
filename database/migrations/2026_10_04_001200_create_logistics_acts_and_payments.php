<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logistika: the logistics company's act for a deal and how it is paid.
 *
 * logistics_acts     — act no/date, amount in its currency, the CBAR rates of the act date and the
 *                      amount in AZN, RUB and EUR at them; the logistics company's own invoice no/date;
 *                      paid today or planned for a later date (with a reminder).
 * logistics_payments — one part of a payment: a share of the act (act currency) paid in a currency
 *                      (RUB / EUR / …) from an account in that currency at the bank's rate, plus the
 *                      bank fee (0.25 %, 25–300 EUR, converted at CBAR for other currencies) on top.
 *                      Every rate, amount, difference and fee is kept for the reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_acts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();   // the seller invoice whose logistics it covers
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete(); // the logistics company
            $t->string('act_number', 64);
            $t->date('act_date');
            $t->string('currency', 8);
            $t->decimal('amount', 18, 2);
            $t->decimal('cbar_rate', 18, 8);        // AZN per 1 act currency
            $t->decimal('cbar_rub', 18, 8);         // AZN per 1 RUB
            $t->decimal('cbar_eur', 18, 8);         // AZN per 1 EUR
            $t->decimal('amount_azn', 18, 2);
            $t->decimal('amount_rub', 18, 2);
            $t->decimal('amount_eur', 18, 2);
            $t->string('logistics_invoice_number', 64)->nullable();
            $t->date('logistics_invoice_date')->nullable();
            $t->string('payment_plan', 8)->default('later'); // today | later
            $t->date('planned_date')->nullable();
            $t->foreignId('reminder_id')->nullable()->constrained()->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'deal_id']);
        });

        Schema::create('logistics_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('logistics_act_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->date('payment_date');
            $t->decimal('act_amount', 18, 2);           // the share of the act settled, act currency
            $t->string('currency', 8);                  // paid in (= the account's currency)
            $t->decimal('cbar_act_rate', 18, 8);        // AZN per 1 act currency
            $t->decimal('cbar_rate', 18, 8);            // AZN per 1 paid currency
            $t->decimal('cbar_cross', 24, 12);          // paid currency per 1 act currency, CBAR
            $t->decimal('bank_rate', 24, 12);           // the bank's (1 when the currencies match)
            $t->decimal('amount_cbar', 18, 2);
            $t->decimal('amount', 18, 2);               // paid for the act share at the bank's rate
            $t->decimal('difference', 18, 2);           // amount - amount_cbar (+ = paid more than CBAR)
            $t->decimal('difference_azn', 18, 2);
            $t->decimal('fee_percent', 8, 4)->nullable();
            $t->decimal('fee_minimum', 18, 2)->nullable(); // in the paid currency
            $t->decimal('fee_maximum', 18, 2)->nullable();
            $t->decimal('fee_amount', 18, 2)->default(0);  // in the paid currency, added on top
            $t->decimal('fee_azn', 18, 2)->default(0);
            $t->decimal('fee_eur', 18, 2)->default(0);
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $t->foreignId('fee_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'logistics_act_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_payments');
        Schema::dropIfExists('logistics_acts');
    }
};
