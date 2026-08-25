<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashBoardService
{
    public function __construct(private AccountingReportService $accounting)
    {
    }

    public function index(): array
    {
        $today = Carbon::today()->toDateString();
        $sales = DB::table('sales_invoices')->whereDate('date', $today)->get();
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
        $expiring = $batches->filter(fn ($batch) => Carbon::parse($batch->expiry_date)->betweenIncluded(Carbon::today(), Carbon::today()->addDays((int) $batch->expiry_alert_days)))
            ->map(fn ($batch) => ['batch_id' => $batch->id, 'product_name' => $batch->name, 'batch_number' => $batch->batch_number, 'expiry_date' => $batch->expiry_date, 'quantity' => $batch->quantity])->values();

        return [
            'today' => $today,
            'todays_sales' => round((float) $sales->sum('total_price'), 2),
            'todays_profit' => round((float) $sales->sum('profit'), 2),
            'inventory_value' => $this->accounting->inventoryValuation($today),
            'low_stock_products' => $lowStock,
            'expiring_batches' => $expiring,
            'customer_balances' => $this->partyBalances('customers', 'customerStatement'),
            'supplier_balances' => $this->partyBalances('providers', 'supplierStatement'),
            'top_selling_products' => DB::table('prod_sales')
                ->join('sales_invoices', 'sales_invoices.id', '=', 'prod_sales.sales_invoice_id')
                ->join('product_batches', 'product_batches.id', '=', 'prod_sales.batch_id')
                ->join('products', 'products.id', '=', 'product_batches.product_id')
                ->whereDate('sales_invoices.date', $today)
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('quantity')->limit(10)
                ->get(['products.id as product_id', 'products.name', DB::raw('SUM(prod_sales.amount) as quantity')]),
        ];
    }

    private function partyBalances(string $table, string $method): array
    {
        return DB::table($table)->get(['id', 'name'])->map(function ($party) use ($method) {
            $statement = $this->accounting->{$method}($party->id);
            return ['id' => $party->id, 'name' => $party->name, 'balance' => $statement['balance']];
        })->values()->all();
    }
}
