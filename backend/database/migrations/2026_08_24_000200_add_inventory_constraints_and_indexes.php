<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->index('product_id');
            $table->index('expiry_date');
            $table->index('deleted_at');
            $table->unique(['product_id', 'batch_number', 'expiry_date'], 'product_batches_identity_unique');
        });

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            Schema::dropIfExists('stock');

            Schema::create('stock', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('product_batches')->cascadeOnDelete();
                $table->integer('quantity');
                $table->string('type');
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });

            DB::statement('CREATE INDEX IF NOT EXISTS stock_batch_id_index ON stock (batch_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS stock_type_index ON stock (type)');
            DB::statement('CREATE INDEX IF NOT EXISTS stock_reference_index ON stock (reference_type, reference_id)');

            return;
        }

        Schema::table('stock', function (Blueprint $table) {
            $table->string('type')->change();
            $table->string('reference_type')->change();
            $table->index('batch_id');
            $table->index('type');
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_type_check");
        DB::statement("ALTER TABLE stock ADD CONSTRAINT stock_type_check CHECK (type IN ('PURCHASE_IN','SALE_OUT','SALES_RETURN_IN','PURCHASE_RETURN_OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT','EXPIRED_OUT','DAMAGED_OUT'))");
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropUnique('product_batches_identity_unique');
            $table->dropIndex(['product_id']);
            $table->dropIndex(['expiry_date']);
            $table->dropIndex(['deleted_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('stock', function (Blueprint $table) {
                $table->dropIndex(['batch_id']);
                $table->dropIndex(['type']);
                $table->dropIndex(['reference_type', 'reference_id']);
            });
        }
    }
};
