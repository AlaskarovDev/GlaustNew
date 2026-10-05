<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logistics: the logistics company's invoice is the main document (its date values the amount at
 * CBAR); the act is optional (number, date, scanned copy as an attachment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_acts', function (Blueprint $t) {
            $t->string('act_number', 64)->nullable()->change();
            $t->date('act_date')->nullable()->change();
        });
        // earlier records were valued at their act date
        DB::table('logistics_acts')->whereNull('logistics_invoice_date')->update(['logistics_invoice_date' => DB::raw('act_date')]);
    }

    public function down(): void
    {
        DB::table('logistics_acts')->whereNull('act_date')->update(['act_date' => DB::raw('logistics_invoice_date')]);
        DB::table('logistics_acts')->whereNull('act_number')->update(['act_number' => DB::raw("coalesce(logistics_invoice_number, '—')")]);
        Schema::table('logistics_acts', function (Blueprint $t) {
            $t->string('act_number', 64)->nullable(false)->change();
            $t->date('act_date')->nullable(false)->change();
        });
    }
};
