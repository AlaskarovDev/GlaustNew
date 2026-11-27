<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seller's invoice: a product code per line, and two optional terms of the invoice itself — the freight it bills
 * (NAVLUN, part of what we owe the seller, unlike our own logistics) and a discount in percent of the goods. Both are
 * spread over the lines in proportion to their Total; the seller's own figures stay in base_unit_price / base_total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $t) {
            $t->string('product_code', 64)->nullable()->after('line_no');
            $t->decimal('base_unit_price', 18, 4)->nullable()->after('total');
            $t->decimal('base_total', 18, 2)->nullable()->after('base_unit_price');
            $t->decimal('freight', 18, 2)->nullable()->after('base_total');
            $t->decimal('discount', 18, 2)->nullable()->after('freight');
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('freight_amount', 18, 2)->nullable();
            $t->decimal('discount_percent', 8, 4)->nullable();
            $t->decimal('discount_amount', 18, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn(['product_code', 'base_unit_price', 'base_total', 'freight', 'discount']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['freight_amount', 'discount_percent', 'discount_amount']));
    }
};
