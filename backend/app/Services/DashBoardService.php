<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashBoardService
{
    public function __construct(private AccountingReportService $accounting)
    {
    }

    public function index($user = null): array
    {
        $today = Carbon::today()->toDateString();
        $revenueLines = $this->accounting->generalLedger($today, $today, '4100');
        $cogsLines = $this->accounting->generalLedger($today, $today, '5100');
        $netSales = round((float) $revenueLines->where('side', 'credit')->sum('amount') - (float) $revenueLines->where('side', 'debit')->sum('amount'), 2);
        $netCogs = round((float) $cogsLines->where('side', 'debit')->sum('amount') - (float) $cogsLines->where('side', 'credit')->sum('amount'), 2);
        $batches = DB::table('product_batches')->join('products', 'products.id', '=', 'product_batches.product_id')
            ->whereNull('product_batches.deleted_at')
            ->get(['product_batches.*', 'products.name']);
        $batches->each(function ($batch) {
            $batch->min_stock_alert = 0;
            $batch->expiry_alert_days = 30;
        });

        $lowStock = $batches->groupBy('product_id')
            ->filter(fn ($items) => $items->sum('quantity') < (int) $items->first()->min_stock_alert)
            ->map(fn ($items) => [
                'product_id' => $items->first()->product_id,
                'product_name' => $items->first()->name,
                'quantity' => $items->sum('quantity'),
                'minimum' => (int) $items->first()->min_stock_alert,
            ])->values();
        $expiring = $batches->filter(fn ($batch) => $batch->expiry_date !== null && Carbon::parse($batch->expiry_date)->betweenIncluded(Carbon::today(), Carbon::today()->addDays((int) $batch->expiry_alert_days)))
            ->map(fn ($batch) => ['batch_id' => $batch->id, 'product_name' => $batch->name, 'batch_number' => $batch->batch_number, 'expiry_date' => $batch->expiry_date, 'quantity' => $batch->quantity])->values();

        $result = [
            'today' => $today,
            'low_stock_products' => $lowStock,
            'expiring_batches' => $expiring,
            'top_selling_products' => DB::table('prod_sales')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'prod_sales.sales_invoice_id')
                ->join('product_batches', 'product_batches.id', '=', 'prod_sales.batch_id')
                ->join('products', 'products.id', '=', 'product_batches.product_id')
                ->whereDate('sales_invoices.date', $today)
                ->whereNull('sales_invoices.deleted_at')
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('quantity')->limit(10)
                ->get(['products.id as product_id', 'products.name', DB::raw('(SUM(prod_sales.amount) - COALESCE((SELECT SUM(invoice_returns.quantity) FROM invoice_returns WHERE invoice_returns.product_id = products.id AND DATE(invoice_returns.created_at) = \''.$today.'\'), 0)) as quantity')]),
        ];
        if ($user === null || $user->hasPermission('reports.view_financial') || $user->hasPermission('accounting.reports')) {
            $result['todays_sales'] = $netSales;
            $result['todays_net_sales'] = $netSales;
            $result['customer_balances'] = $this->partyBalances('customers', 'customerStatement');
            $result['supplier_balances'] = $this->partyBalances('providers', 'supplierStatement');
        }
        if ($user === null || $user->hasPermission('reports.view_cost')) {
            $result['inventory_value'] = $this->accounting->inventoryValuation($today);
        }
        if ($user === null || $user->hasPermission('reports.view_profit')) {
            $income=$this->accounting->incomeStatement($today,$today);
            $result['todays_profit'] = $income['gross_profit'];
            $result['todays_gross_profit'] = $income['gross_profit'];
            $result['todays_gross_margin_percent'] = $income['gross_margin_percent'];
            $result['todays_expenses'] = round($income['operating_expenses']+$income['inventory_adjustments'],2);
            $result['todays_net_profit'] = $income['net_income'];
        }
        return $result;
    }

    private function partyBalances(string $table, string $method): array
    {
        return DB::table($table)->get(['id', 'name'])->map(function ($party) use ($method) {
            $statement = $this->accounting->{$method}($party->id);
            return ['id' => $party->id, 'name' => $party->name, 'balance' => $statement['balance']];
        })->values()->all();
    }
}
