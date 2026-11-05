<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An expense's own «MZ sətri» (e.g. 232.1 — the 232.1.003 «Digər» sub-line): it overrides its category's; null — by the category. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->string('pl_line', 12)->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropColumn('pl_line');
        });
    }
};
