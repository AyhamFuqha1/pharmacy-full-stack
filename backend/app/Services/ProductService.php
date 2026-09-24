<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Product;
use DB;
use Illuminate\Support\Facades\Storage;



class ProductService
{

    public function index($request = null)
    {
        $query = Product::withTrashed();
        $this->applyArchiveStatus($query, $request?->get('archive_status', 'active'));
        if ($request?->filled('search')) {
            $search = preg_replace('/\s+/u', ' ', trim((string) $request->search));
            $normalized = str_replace(['أ', 'إ', 'آ', 'ى'], ['ا', 'ا', 'ا', 'ي'], $search);
            $tokens = array_values(array_filter(explode(' ', $normalized)));
            $nameExpression = "REPLACE(REPLACE(REPLACE(REPLACE(name, 'أ', 'ا'), 'إ', 'ا'), 'آ', 'ا'), 'ى', 'ي')";
            $query->where(function ($q) use ($tokens, $nameExpression) {
                foreach ($tokens as $token) {
                    $q->where(fn ($part) => $part->whereRaw("$nameExpression LIKE ?", ["%$token%"])->orWhere('barcode', 'like', "%$token%"));
                }
            })->orderByRaw("CASE WHEN barcode = ? THEN 0 WHEN $nameExpression = ? THEN 1 WHEN $nameExpression LIKE ? THEN 2 WHEN $nameExpression LIKE ? THEN 3 ELSE 4 END", [$search, $normalized, "$normalized%", "%$normalized%"]);
        }
        $query->when($request?->filled('name'), fn ($q) => $q->where('name', 'like', '%'.$request->name.'%'))
            ->when($request?->filled('barcode'), fn ($q) => $q->where('barcode', 'like', '%'.$request->barcode.'%'))
            ->when($request?->filled('is_controlled'), fn ($q) => $q->where('is_controlled', $request->boolean('is_controlled')))
            ->when($request?->filled('control_schedule'), fn ($q) => $q->where('control_schedule', $request->control_schedule))
            ->when($request?->filled('created_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->created_from))
            ->when($request?->filled('created_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->created_to));
        $sort = in_array($request?->get('sort'), ['name', 'created_at'], true) ? $request->get('sort') : 'created_at';
        $direction = $request?->get('direction') === 'asc' ? 'asc' : 'desc';
        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(min(max((int) ($request?->get('per_page') ?? 15), 1), 100));
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

    public function show($id_product, string $archiveStatus = 'active')
    {
        $query = Product::withTrashed()->where('id', $id_product);
        $this->applyArchiveStatus($query, $archiveStatus);
        return $query->first();
    }

    public function lookupByBarcode(string $barcode, ?int $branchId = null): ?array
    {
        $product = Product::query()->whereNull('deleted_at')->where('barcode', $barcode)->first();
        if (!$product) {
            return null;
        }

        $batches = ProductBatch::query()
            ->where('product_id', $product->id)
            ->availableForSale()
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderBy('expiry_date')->orderBy('id')
            ->get(['id', 'batch_number', 'expiry_date', 'quantity', 'sale_price']);

        $saleUnits=DB::table('product_units')->join('units','units.id','=','product_units.unit_id')->where(['product_units.product_id'=>$product->id,'product_units.is_sale_unit'=>true,'product_units.is_active'=>true])->orderByDesc('product_units.is_base')->orderBy('product_units.id')->get(['units.id',DB::raw('COALESCE(product_units.display_name, units.name) as name'),'units.symbol','product_units.base_quantity','product_units.sale_price','product_units.is_base']);
        $baseSaleUnit=$saleUnits->firstWhere('is_base',true);
        return [
            'product' => $product,
            'available_batches' => $batches,
            'available_sellable_stock' => (int) $batches->sum('quantity'),
            'branch_id' => $branchId,
            'branch_sellable_stock' => (float) $batches->sum('quantity'),
            'base_unit' => $baseSaleUnit,
            'default_price' => (float)($baseSaleUnit->sale_price ?? $batches->first()->sale_price ?? 0),
            'pricing_source' => $baseSaleUnit ? 'product_sale_unit' : 'legacy_fefo_batch_fallback',
            'fefo_candidate_batch' => $batches->first(),
            'sale_units' => $saleUnits,
        ];
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

    public function restore($product): array
    {
        $record = Product::withTrashed()->find($product);
        if (!$record) return ['message' => 'Product not found.', 'status' => 404];
        if ($record->deleted_at === null) return ['message' => 'Product is already active.', 'status' => 409];
        $record->restore();
        return ['message' => 'Product restored.', 'status' => 200, 'data' => $record->fresh()];
    }

    private function applyArchiveStatus($query, string $status): void
    {
        if ($status === 'active') $query->whereNull('deleted_at');
        if ($status === 'archived') $query->whereNotNull('deleted_at');
    }
}
