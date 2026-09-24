<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('discount_type')->default('fixed')->after('discount');
            $table->decimal('discount_value', 14, 2)->default(0)->after('discount_type');
            $table->string('discount_reason')->nullable()->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropColumn(['discount_type','discount_value','discount_reason']));
    }
};
