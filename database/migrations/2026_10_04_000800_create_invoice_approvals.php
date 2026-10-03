<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval of a calculated seller invoice before the commercial invoice is issued to the buyer.
 * The flow (ordered approvers) is configured in Settings and copied onto the invoice when it is
 * submitted, so later changes to the settings do not move a request already in progress.
 * While pending or approved the invoice and its documents cannot be edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('approval_status', 12)->nullable()->after('fx_updated_at'); // pending | approved | rejected
            $t->unsignedSmallInteger('approval_step')->nullable()->after('approval_status');
            $t->json('approval_flow')->nullable()->after('approval_step');
            $t->foreignId('submitted_by')->nullable()->after('approval_flow')->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable()->after('submitted_by');
            $t->timestamp('approved_at')->nullable()->after('submitted_at');
        });

        Schema::create('invoice_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('step')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 12); // submitted | approved | rejected | withdrawn
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['invoice_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_approvals');
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropConstrainedForeignId('submitted_by');
            $t->dropColumn(['approval_status', 'approval_step', 'approval_flow', 'submitted_at', 'approved_at']);
        });
    }
};
