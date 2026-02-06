<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseInvoiceRequest;
use App\Models\PurchaseInvoice;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\Request;

class PurchaseInvoiceController extends Controller
{

    private PurchaseInvoiceService $purchaseInvoiceService;
    public function __construct(PurchaseInvoiceService $purchaseInvoiceService)
    {
        $this->purchaseInvoiceService = $purchaseInvoiceService;
    }
    public function index()
    {
        try {
            $purch_invoices = $this->purchaseInvoiceService->index();
            if (!$purch_invoices) {
                return response()->json([
                    "message" => "No Purch_invoices",
                ], 404);
            } else {
                return response()->json($purch_invoices, 200);

            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function store(StorePurchaseInvoiceRequest $request)
    {
        try {
            $user = auth()->user();
            $result = $this->purchaseInvoiceService->store($request, $user);
            if ($result) {
                return response()->json([
                    'message' => 'Created',
                    'data' => $request->validated()
                ], 201);
            } else {
                return response()->json([
                    'message' => 'Not Created',
                ], 500);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $purch_invoices = $this->purchaseInvoiceService->show($id);
            if (!$purch_invoices) {
                return response()->json([
                    "message" => "this Purch_invoices not found s",
                ], 404);
            } else {
                return response()->json($purch_invoices, 200);

            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function post($id)
    {
        try {
            $user = auth()->user();
            $res = $this->purchaseInvoiceService->post($id, $user);
            if (!$res) {
                return response()->json([
                    "message" => "this invoices posted or caneled"
                ]);
            }
            return response()->json([
                "message" => "this invoices done posted"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cancel($id)
    {
        try {
            $user = auth()->user();
            $res = $this->purchaseInvoiceService->cancel($id, $user);
            if (!$res) {
                return response()->json([
                    "message" => "this invoices posted or caneled"
                ]);
            }
            return response()->json([
                "message" => "this invoices done posted"
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function update(Request $request, $purchaseInvoice)
    {
        try {
            $user = auth()->user();
            $res = $this->purchaseInvoiceService->update($request,$purchaseInvoice, $user);
            if (!$res) {
                return response()->json([
                    "message " => $res
                ], 404);
            }
            return response()->json([
                "message " => $res
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PurchaseInvoice $purchaseInvoice)
    {
        //
    }
}
