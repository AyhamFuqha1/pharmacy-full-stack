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

    public function index(Request $request)
    {
        try {
            $request->validate(['archive_status' => 'nullable|in:active,archived,all', 'created_from' => 'nullable|date', 'created_to' => 'nullable|date|after_or_equal:created_from', 'sort' => 'nullable|in:id,name,created_at', 'direction' => 'nullable|in:asc,desc']);
            return $this->collection($this->customerService->index($request), 'Customers retrieved.');
        } catch (Throwable $e) {
            report($e);
            return $this->failure('Server error.', 500);

        }
    }



    public function store(StoreCustomerRequest $request)
    {
        $this->customerService->store($request);
        return $this->success(null, 201, 'Customer created.');
    }


    public function show($customer)
    {
        try {
            request()->validate(['archive_status' => ['nullable', 'in:active,archived,all']]);
            $Customer = $this->customerService->show($customer, request('archive_status', 'active'));
            if (!$Customer) {
                return $this->failure('Customer not found.', 404);
            } else {

                return $this->success($Customer, 200, 'Customer retrieved.');
            }
        } catch (Throwable $e) {
            report($e);
            return $this->failure('Server error.', 500);

        }
    }

    public function update(UpdateCustomerRequest $request, $customer)
    {
        try {
            $response = $this->customerService->update($request, $customer);
            if (!$response) {
                return $this->failure('Customer not found.', 404);
            }
            return $this->success($this->customerService->show($customer), 200, 'Customer updated.');

        } catch (Throwable $e) {
            report($e);
            return $this->failure('Server error.', 500);

        }

    }


    public function destroy($customer)
    {
        try {
            $response = $this->customerService->destory($customer);
            if (!$response) {
                return $this->failure('Customer not found.', 404);
            }
            return $this->success(null, 200, 'Customer deactivated.');

        } catch (Throwable $e) {
            report($e);
            return $this->failure('Server error.', 500);

        }
    }

    public function restore($customer)
    {
        $result = $this->customerService->restore($customer);
        return $result['status'] >= 400
            ? $this->failure($result['message'], $result['status'])
            : $this->success($result['data'], 200, $result['message']);
    }

    public function incrementDept($id, $amount, $id_sales_invoices)
    {
        try {
            $this->customerService->incrementDept($id, $amount,$id_sales_invoices);
            return response()->json([
                'message' => 'dept applied successfully.'
            ], 200);
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }

    public function decrementDept($id, $amount, $id_sales_invoices)
    {
        try {
            $this->customerService->decrementDept($id, $amount, $id_sales_invoices);
            return response()->json([
                'message' => 'Payment applied successfully.'
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }

}
