<?php

namespace App\Services;
use App\Models\ProductBatch;
use DB;
use Illuminate\Http\Request;
class ProductBatchService
{

    public function index($request = null)
    {
        $query = ProductBatch::withTrashed()->with(['product' => fn ($query) => $query->withTrashed()]);
        $this->applyArchiveStatus($query, $request?->get('archive_status', 'active'));
        if ($request?->filled('search')) {
            $query->where('batch_number', 'like', '%' . $request->search . '%');
        }
        if ($request?->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request?->filled('branch_id')) {
            app(BatchBranchService::class)->applyScope($query, (int) $request->branch_id);
        }
        $query->when($request?->filled('product_search'), fn ($q) => $q->whereHas('product', fn ($p) => $p->withTrashed()->where(fn ($s) => $s->where('name', 'like', '%'.$request->product_search.'%')->orWhere('barcode', 'like', '%'.$request->product_search.'%'))))
            ->when($request?->filled('expiry_from'), fn ($q) => $q->whereDate('expiry_date', '>=', $request->expiry_from))
            ->when($request?->filled('expiry_to'), fn ($q) => $q->whereDate('expiry_date', '<=', $request->expiry_to))
            ->when($request?->filled('quantity_min'), fn ($q) => $q->where('quantity', '>=', $request->quantity_min))
            ->when($request?->filled('quantity_max'), fn ($q) => $q->where('quantity', '<=', $request->quantity_max))
            ->when($request?->stock_status === 'available', fn ($q) => $q->where('quantity', '>', 0)->where(fn ($dates) => $dates->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now())))
            ->when($request?->stock_status === 'out_of_stock', fn ($q) => $q->where('quantity', '<=', 0))
            ->when($request?->stock_status === 'expired', fn ($q) => $q->whereDate('expiry_date', '<', now()))
            ->when($request?->stock_status === 'expiring', fn ($q) => $q->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()]));
        $sort = in_array($request?->get('sort'), ['expiry_date', 'quantity', 'created_at'], true) ? $request->get('sort') : 'expiry_date';
        if ($sort === 'expiry_date') $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END');
        return $query->orderBy($sort, $request?->get('direction') === 'desc' ? 'desc' : 'asc')->orderBy('id')->paginate(min((int) ($request?->get('per_page') ?? 15), 100));
    }


    public function store($request)
    {
        throw new \LogicException('Batches must be created by posting a purchase invoice.');
    }

    
    public function show($productBatch, string $archiveStatus = 'active')
    {
        $query = ProductBatch::withTrashed()->with(['product' => fn ($query) => $query->withTrashed()])->whereKey($productBatch);
        $this->applyArchiveStatus($query, $archiveStatus);
        return $query->first();
    }

   
    public function update($request, $productBatch)
    {
        $batch = ProductBatch::find($productBatch);
        if (!$batch) {
            return false;
        }
        $data = $request->validated();
        unset($data['quantity'], $data['purchase_price']);
        if ($data === []) {
            throw new \LogicException('Quantity and cost can only change through an inventory transaction.');
        }
        $batch->update($data);
        return $batch->fresh()->load('product');
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

    public function restore($productBatch): array
    {
        $batch = ProductBatch::withTrashed()->find($productBatch);
        if (!$batch) return ['message' => 'Batch not found.', 'status' => 404];
        if ($batch->deleted_at === null) return ['message' => 'Batch is already active.', 'status' => 409];
        $batch->restore();
        return ['message' => 'Batch restored.', 'status' => 200, 'data' => $batch->fresh()->load('product')];
    }

    private function applyArchiveStatus($query, string $status): void
    {
        if ($status === 'active') $query->whereNull('product_batches.deleted_at');
        if ($status === 'archived') $query->whereNotNull('product_batches.deleted_at');
    }
}
