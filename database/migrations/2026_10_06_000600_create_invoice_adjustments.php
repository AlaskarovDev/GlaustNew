<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections of a seller invoice (its credit / debit notes), in the invoice currency. A correction of
 * our commercial invoice brings a proportional one automatically: the seller's invoice was wrong too.
 * Existing commercial-invoice corrections get theirs here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sales_document_revision_id')->nullable()->constrained()->nullOnDelete();
            $t->date('adjustment_date');
            $t->string('currency', 8);
            $t->decimal('amount', 18, 2);          // − lowers what we owe the seller, + raises it
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'invoice_id']);
        });

        $revisions = DB::table('sales_document_revisions as r')->join('sales_documents as d', 'd.id', '=', 'r.sales_document_id')
            ->where('r.kind', 'commercial')->whereNotNull('d.source_invoice_id')
            ->select('r.*', 'd.source_invoice_id')->get();
        foreach ($revisions as $r) {
            $inv = DB::table('invoices')->where('id', $r->source_invoice_id)->first();
            if (! $inv || (float) $r->total_before <= 0) {
                continue;
            }
            DB::table('invoice_adjustments')->insert([
                'company_id' => $r->company_id, 'invoice_id' => $inv->id, 'deal_id' => $inv->deal_id, 'sales_document_revision_id' => $r->id,
                'adjustment_date' => substr((string) $r->created_at, 0, 10), 'currency' => $inv->currency,
                'amount' => round((float) $r->difference * (float) $inv->total / (float) $r->total_before, 2),
                'reason' => 'Commercial Invoice düzəlişi ilə'.($r->reason ? ': '.$r->reason : ''), 'created_by' => $r->user_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_adjustments');
    }
};
