<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProductReportingService
{
    public function stockLedger(int $productId, $request)
    {
        $paginator = DB::table('stock as movements')
            ->join('product_batches as batches', 'batches.id', '=', 'movements.batch_id')
            ->join('products', 'products.id', '=', 'batches.product_id')
            ->leftJoin('branches', function ($join) {
                $join->on('branches.id', '=', DB::raw('COALESCE(movements.branch_id, batches.branch_id)'));
            })
            ->leftJoin('users', 'users.id', '=', 'movements.user_id')
            ->where('products.id', $productId)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('movements.created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('movements.created_at', '<=', $request->to))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batches.id', $request->batch_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('movements.branch_id', $request->branch_id))
            ->when($request->filled('movement_type'), fn ($q) => $q->where('movements.type', $request->movement_type))
            ->select([
                'movements.id', 'movements.created_at', 'movements.type as movement_type', 'movements.quantity',
                'movements.reference_type as source_type', 'movements.reference_id as source_id', 'movements.user_id',
                'users.name as user_name', 'products.id as product_id', 'products.name as product_name',
                'products.barcode as product_barcode', 'batches.id as batch_id', 'batches.batch_number',
                'batches.expiry_date as batch_expiry', 'branches.name as branch', 'batches.purchase_price as cost_basis',
            ])
            ->selectRaw("CASE WHEN movements.type LIKE '%IN' OR movements.type = 'IN' THEN movements.quantity ELSE 0 END as quantity_in")
            ->selectRaw("CASE WHEN movements.type LIKE '%OUT' OR movements.type = 'OUT' THEN movements.quantity ELSE 0 END as quantity_out")
            ->orderByDesc('movements.id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));
        $paginator->through(function ($movement) {
            $movement->source_label = app(DocumentReferenceService::class)->label($movement->source_type, $movement->source_id ? (int) $movement->source_id : null);
            return $movement;
        });
        return $paginator;
    }

    public function productReport(int $productId, $request): array
    {
        $movement = DB::table('stock as movements')->join('product_batches as batches', 'batches.id', '=', 'movements.batch_id')
            ->where('batches.product_id', $productId)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('movements.created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('movements.created_at', '<=', $request->to))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batches.id', $request->batch_id));
        $movement->when($request->filled('branch_id'), fn ($q) => $q->where('movements.branch_id', $request->branch_id));

        $sales = DB::table('prod_sales as lines')->join('sales_invoices as invoices', 'invoices.id', '=', 'lines.sales_invoice_id')
            ->join('product_batches as batches', 'batches.id', '=', 'lines.batch_id')->where('batches.product_id', $productId)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('invoices.date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('invoices.date', '<=', $request->to))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batches.id', $request->batch_id));
        $sales->when($request->filled('branch_id'), fn ($q) => $q->where('invoices.branch_id', $request->branch_id));
        $activeSales = (clone $sales)->whereNull('invoices.deleted_at');
        $returns = DB::table('invoice_returns')->where('product_id', $productId)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->batch_id));

        $soldQty = (int) (clone $activeSales)->sum('lines.amount');
        $returnQty = (int) (clone $returns)->sum('quantity');
        $revenue = (float) (clone $activeSales)->sum('lines.final_total') - (float) (clone $returns)->selectRaw('SUM(quantity * price) total')->value('total');
        $cogs = (float) (clone $activeSales)->selectRaw('SUM(lines.amount * lines.cost_price) total')->value('total');
        $returnCost = (float) DB::table('invoice_returns as returns')->join('prod_sales as lines', fn ($join) => $join->on('lines.sales_invoice_id', '=', 'returns.invoice_id')->on('lines.batch_id', '=', 'returns.batch_id'))
            ->where('returns.product_id', $productId)
            ->when($request->filled('from'), fn ($q) => $q->whereDate('returns.created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('returns.created_at', '<=', $request->to))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('returns.batch_id', $request->batch_id))
            ->selectRaw('SUM(returns.quantity * lines.cost_price) total')->value('total');
        $cogs -= $returnCost;
        $profitability = app(ProfitabilityService::class)->product($productId, $request->input('from'), $request->input('to'), $request->integer('branch_id') ?: null);

        return [
            'product_id' => $productId,
            'purchased_quantity' => (int) (clone $movement)->where('movements.type', 'PURCHASE_IN')->sum('movements.quantity'),
            'purchase_return_quantity' => (int) (clone $movement)->where('movements.type', 'PURCHASE_RETURN_OUT')->sum('movements.quantity'),
            'sold_quantity' => $soldQty,
            'sales_return_quantity' => $returnQty,
            'net_sold_quantity' => $soldQty - $returnQty,
            'cancelled_sale_quantity' => (int) (clone $sales)->whereNotNull('invoices.deleted_at')->sum('lines.amount'),
            'prescription_dispensed_quantity' => (int) (clone $movement)->where('movements.type', 'PRESCRIPTION_DISPENSE_OUT')->sum('movements.quantity'),
            'prescription_commercial_disposition' => 'non_commercial',
            'current_stock' => (int) DB::table('product_batches')->where('product_id', $productId)->when($request->filled('batch_id'), fn ($q) => $q->where('id', $request->batch_id))->when($request->filled('branch_id'),fn($q)=>$q->where('branch_id',$request->branch_id))->sum('quantity'),
            'sales_revenue' => $profitability['net_sales_revenue'], 'cogs' => $profitability['cogs'], 'gross_profit' => $profitability['gross_profit'],
            'gross_margin_percent' => $profitability['gross_margin_percent'], 'average_actual_sale_price' => $profitability['average_actual_sale_price'], 'average_historical_cost' => $profitability['average_historical_cost'],
            'return_deductions_other_income' => $profitability['return_deductions_other_income'], 'non_restocked_return_loss' => $profitability['non_restocked_return_loss'], 'inventory_adjustment_losses' => $profitability['inventory_adjustment_losses'], 'theoretical_margin' => $profitability['theoretical_margin'],
            'batches' => DB::table('product_batches')->where('product_id', $productId)->get(['id', 'batch_number', 'expiry_date', 'quantity', 'purchase_price', 'sale_price']),
        ];
    }
}
