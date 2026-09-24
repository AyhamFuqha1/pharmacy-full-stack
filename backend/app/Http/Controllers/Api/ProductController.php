<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\updateaProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\ProductReportingService;
use Illuminate\Http\Request; 

use Throwable;

class ProductController extends Controller
{

    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function index(Request $request)
    {
        try {
            $request->validate(['archive_status' => 'nullable|in:active,archived,all', 'is_controlled' => 'nullable|boolean', 'created_from' => 'nullable|date', 'created_to' => 'nullable|date|after_or_equal:created_from', 'sort' => 'nullable|in:name,created_at', 'direction' => 'nullable|in:asc,desc']);
            $products = $this->productService->index($request);
            if (!$products) {
                return response()->json(
                    ["message" => "No Product"]
                    ,
                    200
                );
            } else {
                return $this->collection($products, 'Products retrieved.');
            }
        } catch (Throwable $e) {
            report($e); return $this->failure('Server error.', 500);

        }
    }


    public function store(StoreProductRequest $request)
    {
        $product = $this->productService->store($request);
        return response()->json([
            'success' => true, 'message' => 'Product created.', 'data' => $product
        ], 201);
    }


    public function show($product)
    {
        try {
            request()->validate(['archive_status' => ['nullable', 'in:active,archived,all']]);
            $products = $this->productService->show($product, request('archive_status', 'active'));
            if (!$products) {
                return $this->failure('Product not found.', 404);
            } else {
                return $this->success($products, 200, 'Product retrieved.');
            }
        } catch (Throwable $e) {
            report($e); return $this->failure('Server error.', 500);

        }
    }

    public function scanBarcode(\Illuminate\Http\Request $request, string $barcode)
    {
        $result = $this->productService->lookupByBarcode($barcode, $request->integer('branch_id') ?: null);

        if (!$result) {
            return $this->failure('Product not found for this barcode.', 404);
        }

        return $this->success($result, 200, 'Product retrieved.');
    }


    public function update(updateaProductRequest $request,$product)
    {

        try {
            $products = $this->productService->update($request, $product);

            if (!$products) {
                return $this->failure('Product not found.', 404);
            } else {
                return $this->success($products, 200, 'Product updated.');
            }
        } catch (Throwable $e) {
            report($e); return $this->failure('Server error.', 500);

        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($product)
    {
        $result = $this->productService->destroy($product);
        return $result['status'] >= 400
            ? $this->failure($result['message'], $result['status'])
            : $this->success(null, $result['status'], $result['message']);
    }

    public function restore($product)
    {
        $result = $this->productService->restore($product);
        return $result['status'] >= 400
            ? $this->failure($result['message'], $result['status'])
            : $this->success($result['data'], 200, $result['message']);
    }

    public function stockLedger(Request $request, int $id, ProductReportingService $reports)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'batch_id' => 'nullable|integer', 'movement_type' => 'nullable|string|max:50', 'per_page' => 'nullable|integer|min:1|max:100']);
        abort_unless(Product::withTrashed()->whereKey($id)->exists(), 404);
        $ledger = $reports->stockLedger($id, $request);
        if (! ($request->user()?->hasPermission('reports.view_cost') || $request->user()?->hasPermission('inventory.view_cost'))) {
            $ledger->through(function ($row) {
                unset($row->cost_basis);
                return $row;
            });
        }
        return $this->collection($ledger, 'Product stock ledger retrieved.');
    }

    public function report(Request $request, int $id, ProductReportingService $reports)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'batch_id' => 'nullable|integer']);
        abort_unless(Product::withTrashed()->whereKey($id)->exists(), 404);
        return $this->success($reports->productReport($id, $request), 200, 'Product report retrieved.');
    }
}
