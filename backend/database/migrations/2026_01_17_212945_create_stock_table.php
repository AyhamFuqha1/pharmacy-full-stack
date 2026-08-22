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
        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('product_batches')->cascadeOnDelete();
            $table->integer('quantity');
            $table->enum('type', ['IN', 'OUT', 'ADJUST']);
            $table->enum('reference_type', ['purchase', 'sale', 'expired', 'adjust']);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp("created_at")->useCurrent();
          
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock');
    }
};
