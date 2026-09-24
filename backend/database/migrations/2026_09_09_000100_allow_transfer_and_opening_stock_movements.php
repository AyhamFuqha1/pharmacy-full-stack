<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = "'PURCHASE_IN','SALE_OUT','SALES_RETURN_IN','PURCHASE_RETURN_OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT','EXPIRED_OUT','DAMAGED_OUT','PRESCRIPTION_DISPENSE_OUT','OPENING_STOCK_IN','TRANSFER_OUT','TRANSFER_IN'";

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            DB::statement('ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_type_check');
            DB::statement('ALTER TABLE stock ADD CONSTRAINT stock_type_check CHECK (type IN ('.self::TYPES.'))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            DB::statement('ALTER TABLE stock DROP CONSTRAINT IF EXISTS stock_type_check');
            DB::statement("ALTER TABLE stock ADD CONSTRAINT stock_type_check CHECK (type IN ('PURCHASE_IN','SALE_OUT','SALES_RETURN_IN','PURCHASE_RETURN_OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT','EXPIRED_OUT','DAMAGED_OUT','PRESCRIPTION_DISPENSE_OUT'))");
        }
    }
};
