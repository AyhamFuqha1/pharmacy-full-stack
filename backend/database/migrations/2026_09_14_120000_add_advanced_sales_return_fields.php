<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoice_returns', function (Blueprint $table) {
            $table->decimal('original_returnable_unit_amount', 14, 2)->nullable()->after('price');
            $table->decimal('customer_credit_unit_amount', 14, 2)->nullable()->after('original_returnable_unit_amount');
            $table->boolean('return_to_inventory')->nullable()->after('customer_credit_unit_amount');
            $table->text('adjustment_reason')->nullable()->after('reason');
            $table->foreignId('created_by')->nullable()->after('adjustment_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['original_returnable_unit_amount', 'customer_credit_unit_amount', 'return_to_inventory', 'adjustment_reason']);
        });
    }
};
