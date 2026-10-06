<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The director who signs documents: ours (company) and the counterparty's. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->string('director_name', 120)->nullable()->after('address'));
        Schema::table('counterparties', fn (Blueprint $t) => $t->string('director_name', 120)->nullable()->after('name'));
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('director_name'));
        Schema::table('counterparties', fn (Blueprint $t) => $t->dropColumn('director_name'));
    }
};
