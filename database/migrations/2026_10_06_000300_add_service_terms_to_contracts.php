<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Service purchase contracts (with a logistics company): route, transport, tariff, transit and payment terms. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->json('service_terms')->nullable()->after('payment_terms'));
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('service_terms'));
    }
};
