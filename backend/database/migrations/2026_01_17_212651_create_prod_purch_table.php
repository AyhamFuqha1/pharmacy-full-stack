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
        Schema::create('prod_purch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            // A batch is created only when the draft purchase is received.
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('batch_number')->nullable();
            $table->date('expire_date')->nullable();
            $table->integer('amount');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->timestamp("created_at")->useCurrent();
            $table->timestamp("updated_at")->useCurrent()->useCurrentOnUpdate();
         //   $table->foreign("purchase_invoice_id")->references("id")->on("purchase_invoices");
         //   $table->foreign("batch_id")->references("id")->on("product_batches");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prod_purch');
    }
};
