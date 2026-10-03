<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A project now has two sides, each with its own contract:
 *   buyer    (counterparty_id, sale_contract_id)     — "məhsulu alan tərəf"
 *   supplier (supplier_id, purchase_contract_id)      — "məhsulu satan tərəf" (seller)
 * The single projects.contract_id is migrated into the matching slot and dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('counterparty_id')->constrained('counterparties')->nullOnDelete();
            $table->foreignId('sale_contract_id')->nullable()->after('supplier_id')->constrained('contracts')->nullOnDelete();
            $table->foreignId('purchase_contract_id')->nullable()->after('sale_contract_id')->constrained('contracts')->nullOnDelete();
        });

        // Old single link -> its slot by contract kind.
        foreach (DB::table('projects')->whereNotNull('contract_id')->get(['id', 'contract_id']) as $p) {
            $c = DB::table('contracts')->where('id', $p->contract_id)->first(['id', 'kind', 'counterparty_id']);
            if ($c) {
                DB::table('projects')->where('id', $p->id)->update($c->kind === 'sale'
                    ? ['sale_contract_id' => $c->id]
                    : ['purchase_contract_id' => $c->id, 'supplier_id' => $c->counterparty_id]);
            }
        }

        // Contracts already pointing at a project fill an empty slot (oldest first).
        foreach (DB::table('contracts')->whereNotNull('project_id')->whereNull('deleted_at')->orderBy('contract_date')->get(['id', 'kind', 'project_id', 'counterparty_id']) as $c) {
            $p = DB::table('projects')->where('id', $c->project_id)->first(['id', 'sale_contract_id', 'purchase_contract_id', 'counterparty_id', 'supplier_id']);
            if (! $p) {
                continue;
            }
            if ($c->kind === 'sale' && ! $p->sale_contract_id) {
                DB::table('projects')->where('id', $p->id)->update(['sale_contract_id' => $c->id, 'counterparty_id' => $p->counterparty_id ?: $c->counterparty_id]);
            } elseif ($c->kind === 'purchase' && ! $p->purchase_contract_id) {
                DB::table('projects')->where('id', $p->id)->update(['purchase_contract_id' => $c->id, 'supplier_id' => $p->supplier_id ?: $c->counterparty_id]);
            }
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropIndex(['contract_id']);
            $table->dropColumn('contract_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });
        DB::table('projects')->update(['contract_id' => DB::raw('COALESCE(sale_contract_id, purchase_contract_id)')]);
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['sale_contract_id']);
            $table->dropForeign(['purchase_contract_id']);
            $table->dropColumn(['supplier_id', 'sale_contract_id', 'purchase_contract_id']);
        });
    }
};
