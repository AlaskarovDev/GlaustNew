<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tədarük (deal): one buy-and-resell lot inside a project. It has a buyer side
 * (customer + sale contract) and a supplier side (supplier + purchase contract), and
 * one or more invoices:
 *   supplier invoice — the seller's proforma to us, imported from Excel;
 *   customer invoice — ours to the buyer, built from it plus our costs (next stage).
 * Each invoice is bound to the contract of its side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('title');
            $table->date('deal_date');
            $table->string('currency', 8)->default('EUR');
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete(); // buyer
            $table->foreignId('sale_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('counterparties')->nullOnDelete();     // seller
            $table->foreignId('purchase_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('status', 16)->default('draft'); // draft | active | invoiced | completed | cancelled
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'project_id']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // supplier | customer
            $table->string('number', 60);         // Proforma N
            $table->date('invoice_date');
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('currency', 8)->default('EUR');
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('cbar_rate', 18, 8)->nullable();
            $table->decimal('total_azn', 18, 2)->nullable();
            $table->string('status', 16)->default('draft'); // draft | confirmed | paid | cancelled
            $table->string('source_file')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'deal_id', 'type', 'number']);
            $table->index(['company_id', 'project_id']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');            // N
            $table->string('description');
            $table->string('hs_code', 20)->nullable();
            $table->decimal('quantity', 18, 3);
            $table->string('uom', 16)->nullable();
            $table->decimal('unit_price', 18, 4);
            $table->decimal('total', 18, 2);               // Total/EUR as given by the seller
            $table->json('extra')->nullable();             // later stages: logistics, CCL, RUR ... (rules to come)
            $table->timestamps();
            $table->index(['invoice_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('deals');
    }
};
