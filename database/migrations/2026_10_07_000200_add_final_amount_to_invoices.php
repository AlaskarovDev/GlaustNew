<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manual ("fakturasız") seller invoice carries its final figure as entered — the amount with every
 * cost included, already known to the user. Logistics, commission and forecast rates are only kept
 * alongside it; they do not recompute it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('final_amount', 18, 2)->nullable()->after('total_azn');
            $t->string('final_currency', 8)->nullable()->after('final_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['final_amount', 'final_currency']);
        });
    }
};
