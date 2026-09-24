<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductUnitController extends Controller
{
    public function index(int $product)
    {
        return $this->success($this->query($product)->get());
    }

    public function store(Request $request,int $product)
    {
        $validated=$request->validate([
            'name'=>'required_without:unit_id|string|max:100','unit_id'=>'required_without:name|nullable|exists:units,id',
            'base_quantity'=>'nullable|numeric|min:0.001','sale_price'=>'nullable|numeric|min:0','is_base'=>'nullable|boolean',
            'is_purchase_unit'=>'nullable|boolean','is_sale_unit'=>'nullable|boolean',
        ]);
        $name=trim((string)($validated['name']??DB::table('units')->where('id',$validated['unit_id'])->value('name')));
        $direct=array_key_exists('name',$validated);
        if($name==='')throw ValidationException::withMessages(['name'=>['Unit name is required.']]);
        $this->assertUniqueName($product,$name);
        $isBase=(bool)($validated['is_base']??false);
        $isSale=(bool)($validated['is_sale_unit']??!($validated['is_purchase_unit']??false));
        if($direct&&$isSale&&!array_key_exists('sale_price',$validated))throw ValidationException::withMessages(['sale_price'=>['Sale price is required for a sale unit.']]);
        if($direct&&$isSale&&!$this->hasActiveBase($product)&&!$isBase)throw ValidationException::withMessages(['is_base'=>['Create the base sale unit first.']]);
        if($isBase&&$this->hasActiveBase($product))throw ValidationException::withMessages(['is_base'=>['This product already has an active base unit.']]);
        $factor=$isBase?1:(float)($validated['base_quantity']??0);
        if(!$isBase&&$factor<=1)throw ValidationException::withMessages(['base_quantity'=>['A package must equal more than one base unit.']]);
        $unitId=(int)($validated['unit_id']??$this->internalUnit($name));
        $id=DB::table('product_units')->insertGetId(['product_id'=>$product,'unit_id'=>$unitId,'display_name'=>$name,'base_quantity'=>$factor,'sale_price'=>$validated['sale_price']??null,'is_base'=>$isBase,'is_purchase_unit'=>$validated['is_purchase_unit']??false,'is_sale_unit'=>$isBase?true:$isSale,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        if($isBase)DB::table('products')->where('id',$product)->update(['base_unit_id'=>$unitId]);
        return $this->success($this->query($product)->where('product_units.id',$id)->first(),201);
    }

    public function update(Request $request,int $product,int $id)
    {
        $validated=$request->validate(['name'=>'sometimes|string|max:100','base_quantity'=>'sometimes|numeric|min:0.001','sale_price'=>'sometimes|numeric|min:0','is_purchase_unit'=>'nullable|boolean','is_sale_unit'=>'nullable|boolean']);
        $unit=DB::table('product_units')->where(['id'=>$id,'product_id'=>$product])->first();abort_unless($unit,404);
        $name=trim((string)($validated['name']??$unit->display_name));if($name==='')throw ValidationException::withMessages(['name'=>['Unit name is required.']]);$this->assertUniqueName($product,$name,$id);
        $factor=$unit->is_base?1:(float)($validated['base_quantity']??$unit->base_quantity);
        if(!$unit->is_base&&$factor<=1)throw ValidationException::withMessages(['base_quantity'=>['A package must equal more than one base unit.']]);
        if($factor!==(float)$unit->base_quantity&&$this->referenced($product,(int)$unit->unit_id))abort(409,'This conversion is used by posted transactions and cannot be changed. Update its price or disable it instead.');
        DB::table('product_units')->where('id',$id)->update(array_merge(collect($validated)->except('name')->all(),['display_name'=>$name,'base_quantity'=>$factor,'updated_at'=>now()]));
        return $this->success($this->query($product)->where('product_units.id',$id)->first());
    }

    public function disable(int $product,int $id)
    {
        $unit=DB::table('product_units')->where(['id'=>$id,'product_id'=>$product])->first();abort_unless($unit,404);
        if($unit->is_base)abort(409,'The active base unit cannot be disabled. Create Sale requires exactly one base unit.');
        DB::table('product_units')->where('id',$id)->update(['is_active'=>false,'updated_at'=>now()]);
        return $this->success($this->query($product)->where('product_units.id',$id)->first());
    }

    public function units(){return $this->success(DB::table('units')->orderBy('name')->get());}

    private function query(int $product){return DB::table('product_units')->join('units','units.id','=','product_units.unit_id')->where('product_units.product_id',$product)->select('product_units.*',DB::raw('COALESCE(product_units.display_name, units.name) as name'),'units.symbol');}
    private function hasActiveBase(int $product):bool{return DB::table('product_units')->where(['product_id'=>$product,'is_base'=>true,'is_active'=>true])->exists();}
    private function assertUniqueName(int $product,string $name,?int $except=null):void{$exists=DB::table('product_units')->where('product_id',$product)->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])->when($except,fn($q)=>$q->where('id','!=',$except))->exists();if($exists)throw ValidationException::withMessages(['name'=>['This product already has a sale unit with that name.']]);}
    private function internalUnit(string $name):int{$existing=DB::table('units')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');if($existing)return(int)$existing;return DB::table('units')->insertGetId(['name'=>$name,'symbol'=>'u-'.substr(hash('sha256',mb_strtolower($name)),0,12),'created_at'=>now(),'updated_at'=>now()]);}
    private function referenced(int $product,int $unit):bool{return DB::table('prod_sales as lines')->join('product_batches as batches','batches.id','=','lines.batch_id')->where('batches.product_id',$product)->where('lines.transaction_unit_id',$unit)->exists()||DB::table('prod_purch')->where(['product_id'=>$product,'transaction_unit_id'=>$unit])->exists();}
}
