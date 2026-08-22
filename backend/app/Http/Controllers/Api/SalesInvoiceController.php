<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Models\SalesInvoice;
use App\Services\SalesInvoiceService;
use Illuminate\Http\Request;

use Throwable;

class SalesInvoiceController extends Controller
{
    private SalesInvoiceService $SalesInvoiceService;
    public function __construct(SalesInvoiceService $salesInvoice)
    {
        $this->SalesInvoiceService = $salesInvoice;
    }
    public function cancel($id)
    {
        try {
            $result = $this->SalesInvoiceService->cancel($id);
            return response()->json(['message' => $result->message], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }

    }
    public function store(StoreSalesInvoiceRequest $request)
    {
        try {
            $user = auth()->user();
            $result = $this->SalesInvoiceService->store($request, $user);
            if ($result) {
                return response()->json([
                    'message' => 'Created',
                    'data' => $request
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
    public function index()
    {
        try {
            $sales_invoices = $this->SalesInvoiceService->index();
            if (!$sales_invoices) {
                return response()->json([
                    "message" => "No sales_invoices",
                ], 404);
            } else {
                return response()->json($sales_invoices, 200);

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
         $res=$this->SalesInvoiceService->show($id);
          if (!$res) {
                return response()->json([
                    "message" => "this Selse_invoices not found ",
                ], 404);
            } else {
                return response()->json($res, 200);

            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }


}
