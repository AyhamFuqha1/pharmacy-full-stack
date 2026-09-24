<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingReportService;
use App\Services\ProfitabilityService;
use Illuminate\Http\Request;
use Throwable;

class AccountingReportController extends Controller
{
    public function __construct(private AccountingReportService $reports)
    {
    }

    public function generalLedger(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'account_code' => 'nullable|string|exists:accounts,code',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        return $this->respond(fn () => $this->reports->paginatedGeneralLedger(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['account_code'] ?? null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
        ));
    }

    public function trialBalance(Request $request)
    {
        return $this->respond(fn () => $this->reports->trialBalance($request->from, $request->to));
    }

    public function incomeStatement(Request $request)
    {
        $validated=$request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from']);
        return $this->respond(fn () => $this->reports->incomeStatement($validated['from']??null, $validated['to']??null));
    }

    public function productProfitability(Request $request, ProfitabilityService $profitability)
    {
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','branch_id'=>'nullable|exists:branches,id','product_id'=>'nullable|exists:products,id','sort'=>'nullable|in:net_sales_revenue,gross_profit,gross_margin_percent','direction'=>'nullable|in:asc,desc','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);
        return $this->respond(fn () => $profitability->products($request));
    }

    public function balanceSheet(Request $request)
    {
        return $this->respond(fn () => $this->reports->balanceSheet($request->to));
    }

    public function customerStatement(Request $request, int $customerId)
    {
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','transaction_type'=>'nullable|string|max:50','reference'=>'nullable|string|max:100','search'=>'nullable|string|max:255','user_id'=>'nullable|integer','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);
        return $this->respond(fn () => $this->reports->customerStatementReport($customerId, $request));
    }

    public function supplierStatement(Request $request, int $supplierId)
    {
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','transaction_type'=>'nullable|string|max:50','reference'=>'nullable|string|max:100','search'=>'nullable|string|max:255','user_id'=>'nullable|integer','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:1|max:100']);
        return $this->respond(fn () => $this->reports->supplierStatementReport($supplierId, $request));
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
