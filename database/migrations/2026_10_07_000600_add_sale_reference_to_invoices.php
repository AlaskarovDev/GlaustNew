<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A manual ("fakturasız") seller invoice may carry the number and date of our invoice to the buyer (e.g. imported from the ATF sheet). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('sale_number', 64)->nullable()->after('final_currency');
            $t->date('sale_date')->nullable()->after('sale_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['sale_number', 'sale_date']);
        });
    }
};
