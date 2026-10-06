<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A currency exchange may belong to a project / Trade (optional) — its CBAR difference then counts in that Trade's profit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $t) {
            $t->foreignId('project_id')->nullable()->after('notes')->constrained()->nullOnDelete();
            $t->foreignId('deal_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $t) {
            $t->dropConstrainedForeignId('deal_id');
            $t->dropConstrainedForeignId('project_id');
        });
    }
};
