<?php

namespace App\Services;

use App\Models\Product;
use DB;
use Illuminate\Support\Facades\Storage;



class ProductService
{

    public function index()
    {
        return Product::query()->whereNull('deleted_at')->get();
    }


    public function store($request)
    {
        $data = $request->validated();

        if ($request->hasFile('img')) {
            $data['img'] = $request->file('img')->store('products', 'public');
        }

        $product = Product::query()->create($data);

        return $product->fresh();
    }

    public function show($id_product)
    {
        return Product::query()->whereNull('deleted_at')->where('id', $id_product)->first();
    }


    public function update($request, $id_product)
    {
        $product = Product::withTrashed()->find($id_product);

        if (!$product || $product->deleted_at !== null) {
            return false;
        }

        $data = $request->validated();

        if ($request->hasFile('img')) {
            if ($product->img && Storage::disk('public')->exists($product->img)) {
                Storage::disk('public')->delete($product->img);
            }
            $data['img'] = $request->file('img')->store('products', 'public');
        }

        $product->fill($data);
        $product->save();

        return $product->fresh();
    }


    public function destroy($product)
    {
        $prod = Product::withTrashed()->find($product);

        if (!$prod) {
            return [
                "message" => "this Product Not Found",
                "status" => 404
            ];
        }

        if ($prod->deleted_at !== null) {
            return [
                "message" => "this Product is already deleted",
                "status" => 409
            ];
        }

        $prod->delete();

        return [
            "message" => "deleted Secssfuled",
            "status" => 200
        ];
    }
}
