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
        $result = $this->ProductBatchService->index($request);
        if (!$result) {
            return response()->json(
                ["  message" => "Not Contant"],
                204
            );

        } else {
            return response()->json($result, 200);
        }
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
        $batches = $this->ProductBatchService->show($id);
        if (!$batches) {
            return response()->json(
                ["message" => "Not Found"],
                404
            );
        } else {
            return response()->json($batches, 200);
        }
    }


    public function update(updateBatchRequest $request, $id)
    {

        $response = $this->ProductBatchService->update($request, $id);

        if (!$response) {
            return response()->json(
                ["message" => "Not Found"],
                404
            );
        } else {
            return response()->json($request, 200);
        }

    }

    public function destroy($id)
    {
        $response = $this->ProductBatchService->destroy($id);
        return response()->json(
            ["message" => $response['message']],
            $response['status']
        );
    }
}
