<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceReturnRequest;
use App\Models\InvoiceReturn;
use App\Services\InvoiceReturnService;
use Illuminate\Http\Request;

class InvoiceReturnController extends Controller
{
    private InvoiceReturnService $invoiceReturnService;
    public function __construct(InvoiceReturnService $invoiceReturnService)
    {
        $this->invoiceReturnService = $invoiceReturnService;
    }

    public function index()
    {

    }


    public function store(StoreInvoiceReturnRequest $request)
    {
        try {
            $user = auth()->user();
            $result = $this->invoiceReturnService->store($request, $user);
            if ($result) {
                return response()->json([
                    'invoice_id' => $result['invoice_id'],
                    'items_count' => $result['items_count'],
                    'total_return' => $result['total_return'],
                ]);
            }

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(InvoiceReturn $invoiceReturn)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InvoiceReturn $invoiceReturn)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(InvoiceReturn $invoiceReturn)
    {
        //
    }
}
