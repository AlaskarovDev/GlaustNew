<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Our commission on a seller's invoice: one rate per invoice, H × rate per line (the sheet's 3.5% column). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('commission_rate', 7, 4)->nullable()->after('logistics_updated_at');
            $t->decimal('commission_total', 18, 2)->nullable()->after('commission_rate');
            $t->timestamp('commission_updated_at')->nullable()->after('commission_total');
        });
        Schema::table('invoice_items', function (Blueprint $t) {
            $t->decimal('commission', 18, 2)->nullable()->after('logistics');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('commission'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['commission_rate', 'commission_total', 'commission_updated_at']));
    }
};
