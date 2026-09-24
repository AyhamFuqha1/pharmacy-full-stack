<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\storeBatchRequest;
use App\Http\Requests\updateBatchRequest;
use App\Services\ProductBatchService;

class ProductBatchController extends Controller
{
    private ProductBatchService $ProductBatchService;
    public function __construct(ProductBatchService $ProductBatchService)
    {
        $this->ProductBatchService = $ProductBatchService;
    }
    public function index(\Illuminate\Http\Request $request)
    {
        $request->validate(['archive_status' => 'nullable|in:active,archived,all', 'product_id' => 'nullable|integer', 'branch_id' => 'nullable|integer|exists:branches,id', 'search' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'expiry_from' => 'nullable|date', 'expiry_to' => 'nullable|date|after_or_equal:expiry_from', 'quantity_min' => 'nullable|integer|min:0', 'quantity_max' => 'nullable|integer|gte:quantity_min', 'stock_status' => 'nullable|in:available,out_of_stock,expired,expiring', 'sort' => 'nullable|in:expiry_date,quantity,created_at', 'direction' => 'nullable|in:asc,desc']);
        $result = $this->ProductBatchService->index($request);
        if (! $this->canViewCost()) {
            $result->through(fn ($batch) => $batch->makeHidden('purchase_price'));
        }
        return $this->collection($result, 'Batches retrieved.');
    }


    public function store(storeBatchRequest $request)
    {
        $batch = $this->ProductBatchService->store($request);
        return response()->json([
            'message' => 'Created',
            'data' => $batch
        ], 201);
    }


    public function show($id)
    {
        request()->validate(['archive_status' => ['nullable', 'in:active,archived,all']]);
        $batches = $this->ProductBatchService->show($id, request('archive_status', 'active'));
        if ($batches && ! $this->canViewCost()) $batches->makeHidden('purchase_price');
        if (!$batches) {
            return $this->failure('Batch not found.', 404);
        } else {
            return $this->success($batches, 200, 'Batch retrieved.');
        }
    }


    public function update(updateBatchRequest $request, $id)
    {

        $response = $this->ProductBatchService->update($request, $id);

        if (!$response) {
            return $this->failure('Batch not found.', 404);
        } else {
            return $this->success($response, 200, 'Batch updated.');
        }

    }

    public function destroy($id)
    {
        $response = $this->ProductBatchService->destroy($id);
        if ($response['status'] >= 400) {
            return $this->failure($response['message'], $response['status']);
        }

        return $this->success(null, $response['status'], $response['message']);
    }

    public function restore($id)
    {
        $response = $this->ProductBatchService->restore($id);
        return $response['status'] >= 400
            ? $this->failure($response['message'], $response['status'])
            : $this->success($response['data'], 200, $response['message']);
    }

    private function canViewCost(): bool
    {
        $user = request()->user();
        return $user?->hasPermission('reports.view_cost') || $user?->hasPermission('inventory.view_cost');
    }
}
