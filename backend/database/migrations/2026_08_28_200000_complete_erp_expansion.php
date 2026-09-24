<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('opening_balance_lines', function (Blueprint $t) {
            $t->decimal('entered_quantity', 14, 3)->nullable();
            $t->foreignId('entered_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $t->decimal('conversion_factor_snapshot', 14, 3)->nullable();
            $t->decimal('normalized_base_quantity', 14, 3)->nullable();
        });
        Schema::table('stock_count_lines', function (Blueprint $t) {
            $t->decimal('entered_quantity', 14, 3)->nullable();
            $t->foreignId('entered_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $t->decimal('conversion_factor_snapshot', 14, 3)->nullable();
            $t->decimal('normalized_base_quantity', 14, 3)->nullable();
        });
        Schema::table('stock_adjustments', function (Blueprint $t) {
            $t->decimal('entered_quantity', 14, 3)->nullable();
            $t->foreignId('entered_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $t->decimal('conversion_factor_snapshot', 14, 3)->nullable();
            $t->decimal('normalized_base_quantity', 14, 3)->nullable();
        });
        Schema::table('stock_transfer_lines', function (Blueprint $t) {
            $t->decimal('entered_quantity', 14, 3)->nullable();
            $t->foreignId('entered_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $t->decimal('conversion_factor_snapshot', 14, 3)->nullable();
            $t->decimal('normalized_base_quantity', 14, 3)->nullable();
        });

        Schema::create('settlement_allocations', function (Blueprint $t) {
            $t->id();
            $t->string('side', 12);
            $t->string('settlement_type', 32);
            $t->unsignedBigInteger('settlement_id');
            $t->string('invoice_type', 32);
            $t->unsignedBigInteger('invoice_id');
            $t->decimal('amount', 14, 2);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['settlement_type', 'settlement_id', 'invoice_type', 'invoice_id'], 'settlement_allocation_unique');
            $t->index(['invoice_type', 'invoice_id']);
            $t->index(['side', 'created_at']);
        });
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_line_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source_type');
            $t->unsignedBigInteger('source_id');
            $t->string('direction', 3);
            $t->decimal('amount', 14, 2);
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['branch_id', 'responsible_user_id', 'occurred_at'], 'cash_attribution_lookup');
            $t->index(['source_type', 'source_id']);
        });
        Schema::table('other_incomes', function (Blueprint $t) {
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancellation_reason')->nullable();
            $t->index(['branch_id', 'date', 'status']);
        });
        Schema::table('bank_reconciliations', function (Blueprint $t) {
            $t->foreignId('journal_entry_line_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamp('unreconciled_at')->nullable();
            $t->foreignId('unreconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['account_id', 'reconciled', 'reconciled_at']);
        });
        Schema::table('import_jobs', function (Blueprint $t) {
            $t->string('original_name')->nullable();
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('failed_rows')->default(0);
            $t->text('failure_message')->nullable();
            $t->timestamp('completed_at')->nullable();
        });
        Schema::table('sales_invoices', function (Blueprint $t) { $t->date('due_date')->nullable()->index(); });
        Schema::table('purchase_invoices', function (Blueprint $t) { $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); $t->index(['branch_id','date','status']); });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', fn (Blueprint $t) => $t->dropColumn('branch_id'));
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('due_date'));
        Schema::table('import_jobs', fn (Blueprint $t) => $t->dropColumn(['original_name','total_rows','valid_rows','failed_rows','failure_message','completed_at']));
        Schema::table('bank_reconciliations', fn (Blueprint $t) => $t->dropColumn(['journal_entry_line_id','unreconciled_at','unreconciled_by']));
        Schema::table('other_incomes', fn (Blueprint $t) => $t->dropColumn(['posted_at','cancelled_at','cancelled_by','cancellation_reason']));
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('settlement_allocations');
        foreach (['stock_transfer_lines','stock_adjustments','stock_count_lines','opening_balance_lines'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['entered_quantity','entered_unit_id','conversion_factor_snapshot','normalized_base_quantity']));
        }
    }
};
