<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->string('display_name', 100)->nullable()->after('unit_id');
            $table->boolean('is_base')->default(false)->after('sale_price');
            $table->index(['product_id','is_base','is_active']);
        });
        DB::table('product_units')->orderBy('id')->each(function ($row) {
            $name=DB::table('units')->where('id',$row->unit_id)->value('name');
            DB::table('product_units')->where('id',$row->id)->update(['display_name'=>$name]);
        });
        DB::table('products')->whereNotNull('base_unit_id')->orderBy('id')->each(function ($product) {
            $existing=DB::table('product_units')->where(['product_id'=>$product->id,'unit_id'=>$product->base_unit_id])->first();
            if($existing&&((float)$existing->base_quantity===1.0))DB::table('product_units')->where('id',$existing->id)->update(['is_base'=>true]);
        });
    }
    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->dropIndex(['product_id','is_base','is_active']);
            $table->dropColumn(['display_name','is_base']);
        });
    }
};
