<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank fee of a logistics payment part may come off any account (as for seller payments): converted
 * from the part's currency at CBAR of the day, or at a bank rate typed for it; the difference is kept in AZN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_payments', function (Blueprint $t) {
            $t->foreignId('fee_account_id')->nullable()->after('fee_eur')->constrained('bank_accounts')->nullOnDelete();
            $t->string('fee_account_currency', 8)->nullable()->after('fee_account_id');
            $t->decimal('fee_account_amount', 18, 2)->nullable()->after('fee_account_currency');
            $t->decimal('fee_bank_rate', 24, 12)->nullable()->after('fee_account_amount');
            $t->decimal('fee_difference_azn', 18, 2)->nullable()->after('fee_bank_rate');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fee_account_id');
            $t->dropColumn(['fee_account_currency', 'fee_account_amount', 'fee_bank_rate', 'fee_difference_azn']);
        });
    }
};
