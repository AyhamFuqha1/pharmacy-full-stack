<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentVoucher;
use App\Services\PaymentVoucherService;
use Illuminate\Http\Request;
use Throwable;

class PaymentVoucherController extends Controller
{
    private PaymentVoucherService $PaymentVoucher;

    public function __construct(PaymentVoucherService $paymentVoucher)
    {
        $this->PaymentVoucher = $paymentVoucher;
    }

    public function index()
    {
        $payments = $this->PaymentVoucher->index();
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
        $payment = $this->PaymentVoucher->store($request);
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

    public function show(PaymentVoucher $paymentVoucher)
    {
        $payment = $this->PaymentVoucher->show($paymentVoucher);
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
    
    public function destroy(PaymentVoucher $paymentVoucher)
    {
        try {
            $result = $this->PaymentVoucher->destroy($paymentVoucher);
            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json(
                ["message" => $e->getMessage()],
                500
            );

        }

    }
}
