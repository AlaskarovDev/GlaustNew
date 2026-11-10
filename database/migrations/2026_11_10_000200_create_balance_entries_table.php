<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balans qalığı (1C): a balance line's figure at a day as the company's books (1C) give it, for the accounts the recorded
 * data cannot make (e.g. 68 — the settlements with the budget). The latest one on or before a balance's day is shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('entry_date');
            $t->string('line', 8);
            $t->decimal('amount', 15, 2);
            $t->string('description');
            $t->string('reference')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'line', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_entries');
    }
};
