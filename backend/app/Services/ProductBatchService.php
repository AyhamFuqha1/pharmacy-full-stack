<?php

namespace App\Services;
use App\Models\ProductBatch;
use DB;
use Illuminate\Http\Request;
class ProductBatchService
{

    public function index($request = null)
    {
        $query = ProductBatch::query()->with('product');
        if ($request?->filled('search')) {
            $query->where('batch_number', 'like', '%' . $request->search . '%');
        }
        if ($request?->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        $sort = in_array($request?->get('sort'), ['expiry_date', 'quantity', 'created_at'], true) ? $request->get('sort') : 'expiry_date';
        return $query->orderBy($sort, $request?->get('direction') === 'desc' ? 'desc' : 'asc')->paginate(min((int) ($request?->get('per_page') ?? 15), 100));
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
        }

        if ($prod->deleted_at != null) {
            return [
                "message" => "this Product is already deleted",
                "status" => 409
            ];
        }

        if ((int) $prod->quantity > 0) {
            return [
                "message" => "Batch cannot be deleted while stock remains.",
                "status" => 422
            ];
        }

        DB::table('product_batches')->where('id', $productBatch)->update(["deleted_at" => now()]);
        return [
            "message" => "deleted Successed",
            "status" => 200
        ];
    }
}
