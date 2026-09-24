<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('invoice_discount', 10, 2)->default(0);
            $table->decimal('total_before_discount', 10, 2)->default(0);
            $table->decimal('total_after_discount', 10, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->string('status')->default('posted')->index();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::table('receipt_vouchers', function (Blueprint $table) {
            $table->string('status')->default('posted')->index();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('status')->default('posted')->index();
            $table->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn(['status', 'cancelled_at']));
        Schema::table('receipt_vouchers', fn (Blueprint $table) => $table->dropColumn(['status', 'cancelled_at']));
        Schema::table('payment_vouchers', fn (Blueprint $table) => $table->dropColumn(['status', 'cancelled_at']));
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn(['invoice_discount', 'total_before_discount', 'total_after_discount', 'due_date', 'status', 'updated_by']));
    }
};
