<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every change of a buyer document's total (proforma, specification, commercial invoice), with the difference. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 16);
            $t->string('currency', 3);
            $t->decimal('total_before', 18, 2);
            $t->decimal('total_after', 18, 2);
            $t->decimal('difference', 18, 2);        // after − before
            $t->string('reason')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'deal_id']);
            $t->index(['sales_document_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_revisions');
    }
};
