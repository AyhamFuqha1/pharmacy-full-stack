<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Stock;
use DB;
use Exception;

class InvoiceReturnService
{
    public function store($request, $user)
    {
        return DB::transaction(function () use ($request, $user) {
            $invoiceId = null;
            $totalReturn = 0;
            $returns = [];
            $stockMoves = [];

            foreach ($request->items as $item) {
                $invoiceId = $item['invoice_id'];
                $saleLine = DB::table('prod_sales')->where('sales_invoice_id', $item['invoice_id'])->where('batch_id', $item['batch_id'])->first();

                if (!$saleLine) {
                    throw new Exception('This batch does not belong to the sales invoice.');
                }

                if ($item['quantity'] > (int) $saleLine->amount) {
                    throw new Exception('Returned quantity exceeds sold quantity.');
                }

                $batch = ProductBatch::query()->whereKey($item['batch_id'])->lockForUpdate()->first();
                if (!$batch || $batch->deleted_at !== null) {
                    throw new Exception('Batch not available for return.');
                }

                $batch->quantity += (int) $item['quantity'];
                $batch->save();

                $returns[] = [
                    'invoice_id' => $item['invoice_id'],
                    'product_id' => $item['product_id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'reason' => $item['reason'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $stockMoves[] = [
                    'batch_id' => $item['batch_id'],
                    'quantity' => (int) $item['quantity'],
                    'type' => 'SALES_RETURN_IN',
                    'reference_type' => 'sales_return',
                    'reference_id' => $item['invoice_id'],
                    'user_id' => $user->id,
                    'created_at' => now(),
                ];

                $totalReturn += $item['quantity'] * $item['price'];
            }

            DB::table('invoice_returns')->insert($returns);
            Stock::query()->insert($stockMoves);

            return [
                'invoice_id' => $invoiceId,
                'items_count' => count($returns),
                'total_return' => $totalReturn,
            ];
        });
    }

    public function purchase($request, $user, $id)
    {
        return DB::transaction(function () use ($request, $user, $id) {
            $invoices = DB::table('purchase_invoices')->where('id', $id)->first();
            if (!$invoices || $invoices->status !== 'posted') {
                return [
                    'status' => false,
                    'message' => 'Invoice not found or not posted',
                ];
            }

            $items = [];
            $stocks = [];
            $total_price = 0;

            foreach ($request->items as $item) {
                $batch = ProductBatch::query()->whereKey($item['batch_id'])->lockForUpdate()->first();
                if (!$batch) {
                    throw new Exception('Batch not found.');
                }

                if ($item['quantity'] > (int) $batch->quantity) {
                    throw new Exception('Not enough stock in batch to process purchase return.');
                }

                $batch->quantity -= (int) $item['quantity'];
                $batch->save();

                $total_price += $item['quantity'] * $item['return_price'];

                $items[] = [
                    'invoice_id' => $id,
                    'invoice_item_id' => $item['invoice_item_id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'return_price' => $item['return_price'],
                    'cost_price' => $item['cost_price'],
                    'line_total_return' => $item['quantity'] * $item['return_price'],
                    'reason' => $item['reasone'] ?? null,
                    'created_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $stocks[] = [
                    'batch_id' => $item['batch_id'],
                    'quantity' => (int) $item['quantity'],
                    'type' => 'PURCHASE_RETURN_OUT',
                    'reference_type' => 'purchase_return',
                    'reference_id' => $id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ];
            }

            DB::table('purch_returns')->insert($items);
            Stock::query()->insert($stocks);

            DB::table('ledgers')->insert([
                'party_type' => 'provider',
                'party_id' => $invoices->provider_id,
                'transaction_type' => 'debit',
                'amount' => $total_price,
                'source_type' => 'purchase_return',
                'source_id' => $id,
                'description' => 'Purchase return - reduced payable to provider',
            ]);
            DB::table('providers')->where('id', $invoices->provider_id)->decrement('dept', $total_price);

            return [
                'status' => true,
                'message' => 'Purchase return completed successfully',
                'invoice_id' => $id,
                'total_return' => $total_price,
            ];
        });
    }
}
