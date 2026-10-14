<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank hesabları auditi: a bank statement (the bank's own Excel) kept line by line, so the system's movements of the
 * same account can be compared with it — and fixed to match — whenever the page is opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $t->string('file_name');
            $t->string('iban', 34)->nullable();
            $t->string('currency', 8);
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('opening', 18, 2)->default(0);
            $t->decimal('closing', 18, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'bank_account_id']);
        });

        Schema::create('bank_audit_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_audit_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('line_no');            // the bank's «№» (0 — the newest)
            $t->date('line_date');
            $t->decimal('amount', 18, 2);               // signed: + in, − out
            $t->decimal('amount_azn', 18, 2)->nullable();
            $t->decimal('balance', 18, 2)->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->index(['bank_audit_id', 'line_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_audit_lines');
        Schema::dropIfExists('bank_audits');
    }
};
