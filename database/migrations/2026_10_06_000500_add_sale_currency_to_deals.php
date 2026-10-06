<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Trade buys in `currency` (the seller's) and sells in `sale_currency` (the buyer's proforma, RUB until now). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->string('sale_currency', 8)->default('RUB')->after('currency'));
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('sale_currency'));
    }
};
