<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MZ düzəlişi: a posting on a line of the P&L by hand (± AZN at a day) — what the company's books (1C) hold and the
 * recorded data cannot give, e.g. the 1C exchange differences or the bank's rates where they part ways by a few manat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('adjustment_date');
            $t->string('line', 12);
            $t->decimal('amount', 15, 2);
            $t->string('description');
            $t->string('reference')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'adjustment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_adjustments');
    }
};
