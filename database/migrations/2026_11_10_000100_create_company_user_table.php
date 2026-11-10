<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memberships: a user may work in several companies, each with its own role and permission overrides. users.company_id
 * stays the company the user is in now (and users.role_id / permission_overrides its role there), so the tenancy and the
 * permissions read what they always did; switching company copies the membership's values in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('permission_overrides')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        $now = now();
        foreach (DB::table('users')->whereNotNull('company_id')->get(['id', 'company_id', 'role_id', 'permission_overrides']) as $u) {
            DB::table('company_user')->insert(['company_id' => $u->company_id, 'user_id' => $u->id, 'role_id' => $u->role_id,
                'permission_overrides' => $u->permission_overrides, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
