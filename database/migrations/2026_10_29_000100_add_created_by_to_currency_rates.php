<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A rate entered by hand (CBAR unreachable): who entered it. CBAR's own rows keep null. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_rates', function (Blueprint $t) {
            $t->foreignId('created_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('currency_rates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
        });
    }
};
