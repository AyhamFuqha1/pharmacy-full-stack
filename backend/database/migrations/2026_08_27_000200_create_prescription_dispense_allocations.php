<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_dispense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->restrictOnDelete();
            $table->foreignId('prescription_item_id')->constrained('prescription_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('product_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['prescription_id', 'prescription_item_id'], 'rx_dispense_prescription_item_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_type_check');
            DB::statement("ALTER TABLE stock ADD CONSTRAINT stock_type_check CHECK (type IN ('PURCHASE_IN','SALE_OUT','SALES_RETURN_IN','PURCHASE_RETURN_OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT','EXPIRED_OUT','DAMAGED_OUT','PRESCRIPTION_DISPENSE_OUT'))");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_type_check');
            DB::statement("ALTER TABLE stock ADD CONSTRAINT stock_type_check CHECK (type IN ('PURCHASE_IN','SALE_OUT','SALES_RETURN_IN','PURCHASE_RETURN_OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT','EXPIRED_OUT','DAMAGED_OUT','PRESCRIPTION_DISPENSE_OUT'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_dispense_allocations');
    }
};
