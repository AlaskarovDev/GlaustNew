<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller invoice is either imported from the seller's Excel (lines of goods, documents for the buyer)
 * or entered as amounts only ("fakturasız"): the seller's total and our total with commission and other
 * amounts; logistics and the RUB conversion run as usual, no documents are prepared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('entry_mode', 16)->default('import')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('entry_mode');
        });
    }
};
