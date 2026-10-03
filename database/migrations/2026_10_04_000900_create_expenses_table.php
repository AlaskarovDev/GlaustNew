<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Xərclər: the company's expenses with their own categories (categories.scope = 'expense').
 * An expense is paid or unpaid; paid in cash or by bank transfer — a transfer writes an
 * outgoing bank movement on the chosen account on the payment date (bank_transaction_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('expense_date');
            $t->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->decimal('amount', 18, 2);
            $t->string('currency', 8)->default('AZN');
            $t->decimal('cbar_rate', 18, 8)->nullable();
            $t->decimal('amount_azn', 18, 2)->nullable();
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 8)->default('unpaid');          // paid | unpaid
            $t->date('due_date')->nullable();                     // for unpaid
            $t->string('payment_method', 8)->nullable();          // cash | bank
            $t->date('paid_at')->nullable();
            $t->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('bank_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'expense_date']);
            $t->index(['company_id', 'status']);
        });

        // Existing companies' system roles get the new module (new companies get it from config).
        $grant = ['accountant' => ['expenses.*'], 'manager' => ['expenses.view', 'expenses.create', 'expenses.update']];
        foreach (DB::table('roles')->whereIn('key', array_keys($grant))->get(['id', 'key', 'permissions']) as $role) {
            $perms = json_decode($role->permissions ?? '[]', true) ?: [];
            $perms = array_values(array_unique(array_merge($perms, $grant[$role->key])));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($perms)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
