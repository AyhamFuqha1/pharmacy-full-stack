<?php

namespace App\Services;

use App\Models\Product;
use DB;
use Illuminate\Support\Facades\Storage;



class ProductService
{

    public function index()
    {
        $prducts = Product::all();
        return $prducts;
    }


    public function store($request)
    {
        $data = $request->validated();
        if ($request->hasFile('img')) {
            $data['img'] = $request->file('img')->store('products', 'public');
        }
        $product_id = DB::table('products')->insertGetId($data);
        return $product_id;
    }

    public function show($id_product)
    {
        $product = DB::table('products')->where('id', $id_product)->first();
        return $product;
    }


    public function update($request, $id_product)
    {

        $product = DB::table('products')->where('id', $id_product)->first();
        
        if (!$product) {
            return false;
        };
        $data = $request->validated();
        if ($request->hasFile('img')) {
            if (Storage::disk('public')->exists($product->img)) {
                Storage::disk('public')->delete($product->img);
                }
                $data['img'] = $request->file('img')->store('products', 'public');
        }
            DB::table('products')->where('id', $id_product)->update($data);
            return true;
        
    }


    public function destroy($product)
    {
        $prod = DB::table('products')->where('id', $product)->first();
        if (!$prod) {
            return [
                "message" => "this Product Not Found",
                "status" => 404
            ];
        } else {
            if ($prod->deleted_at != null) {
                return [
                    "message" => "this Product is already deleted",
                    "status" => 409
                ];
            }

            DB::table('products')->where('id', $product)->update(["deleted_at" => now()]);
            return [
                "message" => "deleted Secssfuled",
                "status" => 200
            ];
        }
    }
}
