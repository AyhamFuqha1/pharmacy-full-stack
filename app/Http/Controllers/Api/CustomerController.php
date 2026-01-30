<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Throwable;
use function PHPUnit\Framework\assertArrayHasKey;

class CustomerController extends Controller
{

    private CustomerService $customerService;
    public function __construct(CustomerService $customerService)
    {
        $this->customerService = $customerService;
    }

    public function index()
    {
        try {
            $customers = $this->customerService->index();
            if (!$customers) {
                return response()->json(
                    ["message" => "No Customer"]
                    ,
                    200
                );
            } else {
                return response()->json(
                    $customers,
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



    public function store(StoreCustomerRequest $request)
    {
        $this->customerService->store($request);
        return response()->json([
            'message' => 'Created',
            'data' => $request
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($customer)
    {
        try {
            $Customer = $this->customerService->show($customer);
            if (!$Customer) {
                return response()->json(
                    ["message" => "This Product Not Found"],
                    200
                );
            } else {

                return response()->json(
                    $Customer,
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

    public function update(UpdateCustomerRequest $request, $customer)
    {
        try {
            $response = $this->customerService->update($request, $customer);
            if (!$response) {
                return response()->jsomne([
                    'message' => "not found customer"
                ], 404);
            }
            return response()->json(['message' => 'Customer updated'], 200);

        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }

    }


    public function destroy($customer)
    {
        try {
            $response = $this->customerService->destory($customer);
            if (!$response) {
                return response()->json([
                    'message' => "not found customer"
                ], 404);
            }
            return response()->json(['message' => 'Customer deactivated'], 200);

        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }
}
