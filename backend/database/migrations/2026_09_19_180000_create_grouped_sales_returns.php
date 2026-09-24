<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('document_number')->unique();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('return_date');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('posted');
            $table->decimal('gross_original_total', 14, 2);
            $table->decimal('customer_credit_total', 14, 2);
            $table->decimal('deduction_total', 14, 2);
            $table->timestamps();
        });

        Schema::table('invoice_returns', function (Blueprint $table) {
            $table->foreignId('sales_return_id')->nullable()->after('id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('original_sale_line_id')->nullable()->after('invoice_id')->constrained('prod_sales')->nullOnDelete();
            $table->index(['sales_return_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_returns', function (Blueprint $table) {
            $table->dropIndex(['sales_return_id', 'invoice_id']);
            $table->dropConstrainedForeignId('original_sale_line_id');
            $table->dropConstrainedForeignId('sales_return_id');
        });
        Schema::dropIfExists('sales_returns');
    }
};
