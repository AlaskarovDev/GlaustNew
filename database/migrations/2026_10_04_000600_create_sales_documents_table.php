<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents we send to the buyer, generated from a seller's invoice once its calculation
 * (logistics, commission, RUB) is complete: the Proforma Invoice (EN) and the Specification
 * to the contract (RU). Both stay editable — every field and line — and are re-rendered to PDF
 * from their current state; the audit log keeps every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $t->string('kind', 16); // proforma | specification
            $t->string('number', 64);
            $t->date('doc_date');
            $t->string('contract_number', 64)->nullable();
            $t->string('contract_date', 32)->nullable(); // as printed, e.g. 09.02.2023
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->string('currency', 3)->default('RUB');
            $t->string('heading')->nullable();          // big company name on the proforma
            $t->text('seller_block')->nullable();
            $t->text('customer_block')->nullable();
            $t->string('payment_terms')->nullable();
            $t->string('delivery_terms')->nullable();
            $t->decimal('freight', 18, 2)->nullable();
            $t->decimal('insurance', 18, 2)->nullable();
            $t->string('seller_signatory')->nullable();
            $t->string('buyer_signatory')->nullable();
            $t->text('notes')->nullable();
            $t->json('lines');                          // [{n, description, hs_code, uom, quantity, unit_price, total}]
            $t->decimal('total', 18, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'deal_id']);
            $t->index(['source_invoice_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_documents');
    }
};
