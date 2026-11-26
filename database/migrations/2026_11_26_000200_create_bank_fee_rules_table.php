<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank komissiyası qaydaları: the bank's fee on an outgoing payment — percent of the amount kept within a minimum and a
 * maximum — per currency, for one bank (bank_name of the paying account) or for every bank (NULL), from a date on.
 * A payment takes the rule in force on its date; before the first rule of a company the system rule
 * (config glaust.bank_fees) holds. Each company keeps its own rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_fee_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('bank_name')->nullable();
            $t->string('currency', 3);
            $t->date('valid_from');
            $t->decimal('percent', 8, 4);
            $t->decimal('minimum', 15, 2)->default(0);
            $t->decimal('maximum', 15, 2)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'currency', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_fee_rules');
    }
};
