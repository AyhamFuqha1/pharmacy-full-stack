<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('prod_purch', function (Blueprint $table) {
            $table->string('transaction_unit_name', 100)->nullable()->after('transaction_unit_id');
            $table->decimal('transaction_unit_cost', 14, 4)->nullable()->after('transaction_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('prod_purch', function (Blueprint $table) {
            $table->dropColumn(['transaction_unit_name', 'transaction_unit_cost']);
        });
    }
};
