<?php

namespace App\Services;
use App\Models\ProductBatch;
use DB;
use Illuminate\Http\Request;
class ProductBatchService
{

    public function index()
    {
        return ProductBatch::all();
    }


    public function store($request)
    {
        $data=$request->validated();
        return ProductBatch::create($data);
    }

    
    public function show($productBatch)
    {
        return ProductBatch::find($productBatch);
    }

   
    public function update($request, $productBatch)
    {
        $batch = ProductBatch::find($productBatch);
        if (!$batch) {
            return false;
        }
        $data = $request->validated();
        $batch->update($data);
        return $productBatch;
    }

    
    public function destroy($productBatch)
    {
        $prod = DB::table('product_batches')->where('id', $productBatch)->first();
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

            DB::table('product_batches')->where('id', $productBatch)->update(["deleted_at" => now()]);
            return [
                "message" => "deleted Successed",
                "status" => 200
            ];
        }

    }
}
