<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prod_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')   ->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')  ->constrained('product_batches')  ->cascadeOnDelete();
            $table->integer('amount');
            $table->decimal('price', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('cost_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->decimal('invoice_discount_share', 10, 2)->default(0);
            $table->decimal('final_total', 10, 2);
            $table->decimal('profit', 10, 2);
            $table->timestamp("created_at")->useCurrent();
            $table->timestamp("updated_at")->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prod_sales');
    }
};
