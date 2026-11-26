<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şirkət qrupu: the companies of one owner (a company and those opened from it). Their users are the same in all of
 * them — each with a role of their own in each company; the data stays apart. NULL: a company on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->unsignedBigInteger('group_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropIndex(['group_id']);
            $t->dropColumn('group_id');
        });
    }
};
