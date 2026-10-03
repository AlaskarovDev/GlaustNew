<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice currency -> RUB for the sheet's RUR columns: rate = D18 / D19, where D18 is AZN per unit
 * of the invoice currency and D19 AZN per rouble — from CBAR on a chosen date, or typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('fx_source', 10)->nullable()->after('commission_updated_at');
            $t->date('fx_date')->nullable()->after('fx_source');
            $t->date('fx_bulletin_date')->nullable()->after('fx_date');
            $t->decimal('fx_base_azn', 18, 8)->nullable()->after('fx_bulletin_date');
            $t->decimal('fx_target_azn', 18, 8)->nullable()->after('fx_base_azn');
            $t->decimal('fx_rate', 24, 12)->nullable()->after('fx_target_azn');
            $t->timestamp('fx_updated_at')->nullable()->after('fx_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['fx_source', 'fx_date', 'fx_bulletin_date', 'fx_base_azn', 'fx_target_azn', 'fx_rate', 'fx_updated_at']));
    }
};
