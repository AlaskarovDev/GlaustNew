<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Incoming payments of a deal (Mədaxillər) are ordinary bank movements linked to the deal. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $t) {
            $t->foreignId('deal_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deal_id');
        });
    }
};
