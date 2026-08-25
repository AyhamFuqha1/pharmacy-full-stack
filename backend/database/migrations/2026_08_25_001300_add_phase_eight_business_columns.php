<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('role')->constrained('branches')->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->index('branch_id');
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->index('branch_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_controlled')->default(false)->after('is_active');
            $table->string('control_schedule')->nullable()->after('is_controlled');
            $table->index(['is_controlled', 'control_schedule']);
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->after('customer_id')->constrained('patients')->nullOnDelete();
            $table->foreignId('prescription_id')->nullable()->after('patient_id')->constrained('prescriptions')->nullOnDelete();
            $table->decimal('paid_amount', 10, 2)->default(0)->after('discount');
            $table->string('payment_method')->default('cash')->after('paid_amount');
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->index(['branch_id', 'date']);
        });

        Schema::table('prod_sales', function (Blueprint $table) {
            $table->foreignId('prescription_item_id')->nullable()->after('batch_id')->constrained('prescription_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prod_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_item_id');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'date']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('patient_id');
            $table->dropConstrainedForeignId('prescription_id');
            $table->dropColumn(['paid_amount', 'payment_method', 'deleted_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_controlled', 'control_schedule']);
            $table->dropColumn(['is_controlled', 'control_schedule']);
        });

        Schema::table('providers', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
