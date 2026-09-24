<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoicePurchReturnRequest;
use App\Http\Requests\StoreInvoiceReturnRequest;
use App\Models\InvoiceReturn;
use App\Services\InvoiceReturnService;
use Illuminate\Http\Request;
use App\Exceptions\AccountingPeriodException;

class 
InvoiceReturnController extends Controller
{
    private InvoiceReturnService $invoiceReturnService;
    public function __construct(InvoiceReturnService $invoiceReturnService)
    {
        $this->invoiceReturnService = $invoiceReturnService;
    }

    public function salesIndex(Request $request)
    {
        $this->validateHistoryFilters($request, 'customer_id');
        return $this->collection($this->invoiceReturnService->salesIndex($request), 'Sales returns retrieved.');
    }

    public function salesShow(int $id)
    {
        $return = $this->invoiceReturnService->salesShow($id);
        return $return ? $this->success($return, 200, 'Sales return retrieved.') : $this->failure('Sales return not found.', 404);
    }

    public function purchaseIndex(Request $request)
    {
        $this->validateHistoryFilters($request, 'provider_id');
        return $this->collection($this->invoiceReturnService->purchaseIndex($request), 'Purchase returns retrieved.');
    }

    public function purchaseShow(int $id)
    {
        $return = $this->invoiceReturnService->purchaseShow($id);
        return $return ? $this->success($return, 200, 'Purchase return retrieved.') : $this->failure('Purchase return not found.', 404);
    }
    public function store(StoreInvoiceReturnRequest $request)
    {
        try {
            $user = auth()->user();
            $result = $this->invoiceReturnService->store($request, $user);
            if ($result) {
                return $this->success($result, 201, 'Sales return created.');
            }

        } catch (AccountingPeriodException $e) {
            throw $e;
        } catch (\DomainException $e) {
            return $this->failure($e->getMessage(), 409);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false, 'message' => 'Server error.', 'errors' => (object) []
            ], 500);
        }
    }
    public function purchase(StoreInvoicePurchReturnRequest $request,$id)
    {
        try {
            $user = auth()->user();
            $result = $this->invoiceReturnService->purchase($request, $user,$id);
              return $result['status'] ? $this->success($result, 201, 'Purchase return created.') : $this->failure($result['message'], 409);

        } catch (AccountingPeriodException $e) {
            throw $e;
        } catch (\DomainException $e) {
            return $this->failure($e->getMessage(), 409);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false, 'message' => 'Server error.', 'errors' => (object) []
            ], 500);
        }
    }
    public function show(InvoiceReturn $invoiceReturn)
    {
        //
    }
    public function update(Request $request, InvoiceReturn $invoiceReturn)
    {
        //
    }
    public function destroy(InvoiceReturn $invoiceReturn)
    {
        //
    }

    private function validateHistoryFilters(Request $request, string $partyKey): void
    {
        $request->validate([
            'return_id' => 'nullable|integer|min:1', 'return_number' => 'nullable|string|max:100', 'source_invoice_id' => 'nullable|integer|min:1', 'source_document_number' => 'nullable|string|max:100',
            $partyKey => 'nullable|integer|min:1', 'product_id' => 'nullable|integer|min:1', 'batch_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'user_id' => 'nullable|integer|min:1',
            'product_search' => 'nullable|string|max:255', 'batch_search' => 'nullable|string|max:255', 'customer_search' => 'nullable|string|max:255', 'provider_search' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:255', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }
}
