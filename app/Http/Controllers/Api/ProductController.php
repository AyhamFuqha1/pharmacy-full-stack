<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\updateaProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request; 

use Throwable;

class ProductController extends Controller
{

    private ProductService $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function index()
    {
        try {
            $products = $this->productService->index();
            if (!$products) {
                return response()->json(
                    ["message" => "No Product"]
                    ,
                    200
                );
            } else {
                return response()->json(
                    $products,
                    200
                );
            }
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }


    public function store(StoreProductRequest $request)
    {
        $this->productService->store($request);
          return response()->json([
            'message' => 'Created',
            'data' => $request
        ], 201);
    }


    public function show($product)
    {
        try {
            $products = $this->productService->show($product);
            if (!$products) {
                return response()->json(
                    ["message" => "This Product Not Found"],
                    200
                );
            } else {
                return response()->json(
                    $products,
                    200
                );
            }
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }


    public function update(updateaProductRequest $request,$product)
    {
     
        try {
            $products = $this->productService->update($request, $product);
           
            if (!$products) {
                return response()->json(
                    ["message" => "This Product Not Found"],
                    404
                );
            } else {
                return response()->json(
                    $products,
                    200
                );
            }
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($product)
    {
        $result = $this->productService->destroy($product);
        return response()->json(
            ["message"=>$result['message']],
            $result['status']
        );
    }
}
