<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank fee of a seller payment paid from an account in another currency may be converted at the
 * bank's rate ("Bank kursu ilə hesabla") instead of CBAR; its difference to CBAR is kept in AZN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->decimal('fee_bank_rate', 24, 12)->nullable()->after('fee_account_currency');   // fee account currency per 1 fee (payment) currency
            $t->decimal('fee_difference_azn', 18, 2)->nullable()->after('fee_bank_rate');       // + paid more than at CBAR
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropColumn(['fee_bank_rate', 'fee_difference_azn']);
        });
    }
};
