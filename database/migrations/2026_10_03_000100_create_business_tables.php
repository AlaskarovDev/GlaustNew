<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Business modules. Money: DECIMAL(18,2). Exchange rates: DECIMAL(18,8) —
 * a CBAR rouble rate has 6 decimals (0.021091) and DECIMAL(x,4) rounds it to
 * 0.0211, which moved a 9.9 M RUB record by ~94 AZN in the old Glaust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 8);
            $table->date('rate_date');         // the day the rate is in force for
            $table->date('bulletin_date')->nullable(); // ValCurs@Date: CBAR answers weekends/unpublished days with the last bulletin
            $table->decimal('rate', 18, 8); // AZN per ONE unit
            $table->decimal('nominal', 12, 2)->default(1);
            $table->decimal('value', 18, 8); // as published (per nominal)
            $table->string('name')->nullable();
            $table->string('source', 16)->default('CBAR');
            $table->timestamps();
            $table->unique(['currency_code', 'rate_date']);
            $table->index('rate_date');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32); // bank | logistics_cost | counterparty_tag
            $table->string('name');
            $table->string('color', 9)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'scope', 'name']);
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // customer | supplier | both
            $table->string('entity_type', 16)->default('legal'); // legal | individual
            $table->string('name');
            $table->string('voen', 10)->nullable();
            $table->string('country', 64)->default('Azərbaycan');
            $table->string('city', 64)->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('swift', 11)->nullable();
            $table->string('tags')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'voen']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('counterparty_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable()->index(); // FK added after contracts
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('planned'); // planned | active | on_hold | completed | cancelled
            $table->string('priority', 10)->default('medium'); // low | medium | high | critical
            $table->decimal('budget', 18, 2)->default(0);
            $table->string('currency', 8)->default('AZN');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->date('contract_date');
            // A contract can only exist with a counterparty from CRM: NOT NULL + RESTRICT.
            $table->foreignId('counterparty_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16); // sale | purchase
            $table->string('subject');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('currency', 8)->default('AZN');
            $table->decimal('cbar_rate', 18, 8)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_azn', 18, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable()->index();
            $table->boolean('auto_renew')->default(false);
            $table->string('payment_terms')->nullable();
            $table->string('status', 16)->default('draft'); // draft | signed | active | completed | cancelled
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('contracts')->nullOnDelete(); // additional agreements
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });

        Schema::create('contract_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->date('due_date')->index();
            $table->decimal('amount', 18, 2);
            $table->string('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('todo'); // todo | in_progress | review | done
            $table->string('priority', 10)->default('medium');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'assignee_id', 'status']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('minutes');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bank_name');
            $table->string('iban', 34)->nullable();
            $table->string('currency', 8)->default('AZN');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 4); // in | out
            $table->string('kind', 16)->default('regular'); // regular | transfer | conversion
            $table->uuid('transfer_group')->nullable()->index();
            $table->date('transaction_date')->index();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 8);
            $table->decimal('cbar_rate', 18, 8);
            $table->decimal('applied_rate', 18, 8);
            $table->decimal('amount_azn', 18, 2);      // at applied rate
            $table->decimal('cbar_amount_azn', 18, 2); // at the official CBAR rate
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('import_hash', 64)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'transaction_date']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('direction', 10); // import | export | domestic
            $table->string('origin');
            $table->string('destination');
            $table->foreignId('carrier_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->string('transport_mode', 10); // road | rail | sea | air
            $table->string('vehicle')->nullable();
            $table->string('container_no', 40)->nullable();
            $table->string('document_no', 60)->nullable(); // CMR / B/L / AWB
            $table->string('cargo_description')->nullable();
            $table->decimal('weight_kg', 14, 2)->nullable();
            $table->decimal('volume_m3', 14, 3)->nullable();
            $table->date('loading_date')->nullable();
            $table->date('eta')->nullable()->index();
            $table->timestamp('delivered_at')->nullable();
            $table->string('status', 16)->default('planned'); // planned | loading | in_transit | customs | arrived | delivered
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('shipment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('shipment_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('cost_type', 16); // freight | customs | insurance | storage | other
            $table->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $table->date('cost_date');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 8);
            $table->decimal('cbar_rate', 18, 8);
            $table->decimal('amount_azn', 18, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->morphs('attachable');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24); // personal | contract_end | contract_payment | task_due | shipment_delay
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->nullableMorphs('remindable');
            $table->timestamp('remind_at')->index();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at', 'remind_at']);
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('to');
            $table->string('subject');
            $table->string('kind', 32); // reminder | digest | invitation | password_reset | test
            $table->string('status', 10); // sent | failed
            $table->text('error')->nullable();
            $table->string('transport', 16)->nullable(); // company | platform
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('original_name');
            $table->string('path');
            $table->json('mapping')->nullable();
            $table->string('status', 16)->default('uploaded'); // uploaded | queued | processing | done | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('error_path')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['imports', 'mail_logs', 'reminders', 'attachments', 'shipment_costs', 'shipment_status_history', 'shipments',
            'bank_transactions', 'bank_accounts', 'time_entries', 'task_comments', 'task_checklist_items', 'tasks',
            'contract_payments'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('projects', fn (Blueprint $table) => $table->dropForeign(['contract_id']));
        foreach (['contracts', 'milestones', 'project_user', 'projects', 'counterparty_contacts', 'counterparties', 'categories', 'currency_rates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
