<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A logistics payment part whose amount already includes the bank fee: the carrier got the amount minus the fee. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_payments', function (Blueprint $t) {
            $t->boolean('fee_included')->default(false)->after('fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_payments', function (Blueprint $t) {
            $t->dropColumn('fee_included');
        });
    }
};
