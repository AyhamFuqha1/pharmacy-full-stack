<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingReportService;
use Illuminate\Http\Request;
use Throwable;

class AccountingReportController extends Controller
{
    public function __construct(private AccountingReportService $reports)
    {
    }

    public function generalLedger(Request $request)
    {
        return $this->respond(fn () => $this->reports->generalLedger($request->from, $request->to, $request->account_code));
    }

    public function trialBalance(Request $request)
    {
        return $this->respond(fn () => $this->reports->trialBalance($request->from, $request->to));
    }

    public function incomeStatement(Request $request)
    {
        return $this->respond(fn () => $this->reports->incomeStatement($request->from, $request->to));
    }

    public function balanceSheet(Request $request)
    {
        return $this->respond(fn () => $this->reports->balanceSheet($request->to));
    }

    public function customerStatement(Request $request, int $customerId)
    {
        return $this->respond(fn () => $this->reports->customerStatement($customerId, $request->from, $request->to));
    }

    public function supplierStatement(Request $request, int $supplierId)
    {
        return $this->respond(fn () => $this->reports->supplierStatement($supplierId, $request->from, $request->to));
    }

    public function inventoryValuation(Request $request)
    {
        return $this->respond(fn () => $this->reports->inventoryValuation($request->to));
    }

    private function respond(callable $callback)
    {
        try {
            return response()->json($callback());
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
