<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Logistics cost of a seller's invoice, entered after the Excel import:
 *   mode   forecast | actual
 *   method total    — one amount, split over lines by Total/EUR share (=H*$I$total/$H$total)
 *          per_item — an amount per line, all in ONE currency
 * The entered currency is converted to the invoice currency at the CBAR rates of the
 * invoice date; both the entered and the converted amounts are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('logistics_mode', 10)->nullable()->after('total_azn');      // forecast | actual
            $table->string('logistics_method', 10)->nullable()->after('logistics_mode'); // total | per_item
            $table->string('logistics_currency', 8)->nullable()->after('logistics_method');
            $table->decimal('logistics_amount', 18, 2)->nullable()->after('logistics_currency'); // as entered
            $table->decimal('logistics_rate', 18, 8)->nullable()->after('logistics_amount');    // entered currency -> invoice currency
            $table->decimal('logistics_total', 18, 2)->nullable()->after('logistics_rate');     // in invoice currency
            $table->timestamp('logistics_updated_at')->nullable()->after('logistics_total');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('logistics_original', 18, 2)->nullable()->after('total'); // entered currency
            $table->decimal('logistics', 18, 2)->nullable()->after('logistics_original'); // invoice currency
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn(['logistics_original', 'logistics']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['logistics_mode', 'logistics_method', 'logistics_currency', 'logistics_amount', 'logistics_rate', 'logistics_total', 'logistics_updated_at']));
    }
};
