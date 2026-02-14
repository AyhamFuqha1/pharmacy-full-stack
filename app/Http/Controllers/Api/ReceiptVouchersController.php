<?php

namespace App\Http\Controllers;

use App\Models\ReceiptVouchers;
use App\Services\ReceiptVouchersServices;
use Illuminate\Http\Request;
use Throwable;

class ReceiptVouchersController extends Controller
{

    private ReceiptVouchersServices $receiptVouchersServices;
    public function __construct(ReceiptVouchersServices $receiptVouchersServices)
    {
        $this->receiptVouchersServices = $receiptVouchersServices;
    }
    public function index()
    {
        $payments = $this->receiptVouchersServices->index();
        try {
            if (!$payments) {
                return response()->json(
                    ["message" => "No Product"]
                    ,
                    200
                );
            } else {
                return response()->json(
                    $payments,
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
    public function store(Request $request)
    {
        $payment = $this->receiptVouchersServices->store($request);
        try {
            if (!$payment) {
                return response()->json(
                    ["message" => "Not Save"]
                    ,
                    404
                );
            } else {
                return response()->json(
                    $payment,
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
    public function show(ReceiptVouchers $receiptVouchers)
    {
        $payment = $this->receiptVouchersServices->show($receiptVouchers);
        try {
            if (!$payment) {
                return response()->json(
                    ["message" => "This product Not Found"]
                    ,
                    404
                );
            } else {
                return response()->json(
                    $payment,
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
    public function destroy(ReceiptVouchers $receiptVouchers)
    {
        try {
            $result = $this->receiptVouchersServices->destroy($receiptVouchers);
            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }
    }
}
