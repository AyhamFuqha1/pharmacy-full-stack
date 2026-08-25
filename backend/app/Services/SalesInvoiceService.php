<?php
namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Stock;
use DB;
use Exception;

class SalesInvoiceService
{
    public function index()
    {
        return DB::table('sales_invoices')->get();
    }

    public function cancel($id)
    {
        return DB::transaction(function () use ($id) {
            $response = DB::table('sales_invoices')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$response) {
                return ['message' => 'Invoice already cancelled'];
            }

            foreach (DB::table('prod_sales')->where('sales_invoice_id', $id)->get() as $line) {
                $batch = ProductBatch::query()->whereKey($line->batch_id)->lockForUpdate()->first();
                if ($batch) {
                    $batch->quantity += (int) $line->amount;
                    $batch->save();
                }

                Stock::query()->create([
                    'batch_id' => $line->batch_id,
                    'quantity' => (int) $line->amount,
                    'type' => 'SALES_RETURN_IN',
                    'reference_type' => 'sales_cancel',
                    'reference_id' => $id,
                    'user_id' => null,
                    'created_at' => now(),
                ]);
            }

            DB::table('prod_sales')->where('sales_invoice_id', $id)->delete();
            DB::table('sales_invoices')->where('id', $id)->update(['deleted_at' => now()]);

            return ['message' => 'Deleted is Done'];
        });
    }

    public function store($request, $user)
    {
        return DB::transaction(function () use ($request, $user) {
            if (empty($request->items)) {
                return ['message' => 'items is empty'];
            }

            $total_before = 0;
            foreach ($request->items as $item) {
                $total_before += ($item['price'] * $item['amount']) - ($item['discount'] ?? 0);
            }

            $invoiceId = DB::table('sales_invoices')->insertGetId([
                'branch_id' => $request['branch_id'] ?? $this->resolveBranchId($user),
                'customer_id' => $request['customer_id'],
                'patient_id' => $request['patient_id'] ?? null,
                'prescription_id' => $request['prescription_id'] ?? null,
                'user_id' => $user->id,
                'discount' => $request['discount'] ?? 0,
                'paid_amount' => $request['paid_amount'] ?? 0,
                'payment_method' => $request['payment_method'] ?? 'cash',
                'date' => $request['date'],
                'total_price' => $total_before,
                'profit' => 0,
            ]);

            $saleLines = [];
            $profit = 0;

            foreach ($request->items as $item) {
                $required = (int) $item['amount'];
                $remaining = $required;

                $candidateBatches = $this->resolveSaleBatches($item);

                if ($candidateBatches->isEmpty()) {
                    throw new Exception('No valid batch available for sale item.');
                }

                foreach ($candidateBatches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, (int) $batch->quantity);
                    if ($take <= 0) {
                        continue;
                    }

                    if ($batch->deleted_at !== null || $batch->expiry_date < now()->toDateString() || $batch->quantity <= 0) {
                        continue;
                    }

                    $batch->applySale($take);
                    $remaining -= $take;

                    $lineTotal = ($item['price'] * $take) - ($item['discount'] ?? 0);
                    $batchCost = (float) $batch->purchase_price;
                    $profit += $lineTotal - ($take * $batchCost);

                    $saleLines[] = [
                        'sales_invoice_id' => $invoiceId,
                        'batch_id' => $batch->id,
                        'prescription_item_id' => $item['prescription_item_id'] ?? null,
                        'amount' => $take,
                        'price' => $item['price'],
                        'discount' => $item['discount'] ?? 0,
                        'cost_price' => $batchCost,
                        'line_total' => $lineTotal,
                        'invoice_discount_share' => 0,
                        'final_total' => $lineTotal,
                        'profit' => $lineTotal - ($take * $batchCost),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                }

                if ($remaining > 0) {
                    throw new Exception('Insufficient stock for item.');
                }
            }

            DB::table('prod_sales')->insert($saleLines);

            DB::table('sales_invoices')->where('id', $invoiceId)->update([
                'total_price' => $total_before,
                'profit' => $profit,
            ]);

            return true;
        });
    }

    protected function resolveSaleBatches($item)
    {
        if (!empty($item['batch_id'])) {
            return ProductBatch::query()->whereKey($item['batch_id'])->whereNull('deleted_at')->where('quantity', '>', 0)->whereDate('expiry_date', '>=', now()->toDateString())->lockForUpdate()->get();
        }

        if (!empty($item['product_id'])) {
            return ProductBatch::query()
                ->where('product_id', $item['product_id'])
                ->whereNull('deleted_at')
                ->where('quantity', '>', 0)
                ->whereDate('expiry_date', '>=', now()->toDateString())
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
        }

        if (!empty($item['barcode'])) {
            $productId = DB::table('products')->whereNull('deleted_at')->where('barcode', $item['barcode'])->value('id');
            if (!$productId) {
                return collect();
            }

            return ProductBatch::query()
                ->where('product_id', $productId)
                ->whereNull('deleted_at')
                ->where('quantity', '>', 0)
                ->whereDate('expiry_date', '>=', now()->toDateString())
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
        }

        return collect();
    }

    private function resolveBranchId($user): ?int
    {
        if (!empty($user->branch_id)) {
            return (int) $user->branch_id;
        }

        return DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');
    }

    public function show($id)
    {
        return DB::table('sales_invoices')->where('id', $id)->first();
    }
}
