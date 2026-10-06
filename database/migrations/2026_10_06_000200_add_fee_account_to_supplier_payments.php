<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The bank fee of a payment to the seller may come off another account (any currency, converted at CBAR). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->foreignId('fee_account_id')->nullable()->after('fee_account_amount')->constrained('bank_accounts')->nullOnDelete();
            $t->string('fee_account_currency', 8)->nullable()->after('fee_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fee_account_id');
            $t->dropColumn('fee_account_currency');
        });
    }
};
