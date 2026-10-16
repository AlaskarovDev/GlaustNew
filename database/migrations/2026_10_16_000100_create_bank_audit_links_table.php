<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank audit: lines of the statement and movements of the system the user tied together by hand («Birləşdir»),
 * e.g. five small bank fees of a day against the one fee expense booked for them. One row per member of a group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_audit_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_audit_id')->constrained()->cascadeOnDelete();
            $t->uuid('group');
            $t->foreignId('bank_audit_line_id')->nullable()->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('bank_transaction_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['bank_audit_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_audit_links');
    }
};
