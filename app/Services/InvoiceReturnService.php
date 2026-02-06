<?php

namespace App\Services;

use DB;
use Exception;
class InvoiceReturnService
{
    public function store($request, $user)
    {

        return DB::transaction(function () use ($request, $user) {
            $returns = [];
            $stocks = [];
            $invoiceId = null;
            $totalReturn = 0;
            foreach ($request->items as $item) {
                $invoiceId = $item['invoice_id'];
                $returns[] = [
                    'invoice_id' => $item['invoice_id'],
                    'product_id' => $item['product_id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'reason' => $item['reason'],
                ];

                $stocks[] = [
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'type' => 'IN',
                    'reference_type' => 'return',
                    'reference_id' => $item['invoice_id'],
                    'user_id' => $user->id
                ];
                $totalReturn += $item['quantity'] * $item['price'];
                DB::table('product_batches')
                    ->where('id', $item['batch_id'])
                    ->increment('quantity', $item['quantity']);
            }
            DB::table('invoice_returns')->insert($returns);
            DB::table('stock')->insert($stocks);
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
            $invoices = DB::table("purchase_invoices")->where('id', $id)->first();
            if (!$invoices || $invoices->status !== "posted") {
                return [
                    'status' => false,
                    'message' => 'Invoice not found or not posted'
                ];

            }
            $items = [];
            $stocks = [];
            $total_price = 0;
            foreach ($request->items as $item) {
                $total_price += $item['quantity'] * $item['return_price'];
                $items[] = [
                    'invoice_id' => $id,
                    'invoice_item_id' => $item['invoice_item_id'],
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'return_price' => $item['return_price'],
                    'cost_price' => $item['cost_price'],
                    'line_total_return' => $item['quantity'] * $item['return_price'],
                    'reason' => $item['reasone'],
                    'created_by' => $user->id,
                ];
                $stocks[] = [
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['quantity'],
                    'type' => 'OUT',
                    'reference_type' => 'adjust',
                    'reference_id' => $id,
                    'user_id' => $user->id,
                    'created_at' => now()
                ];


                DB::table('product_batches')
                    ->where('id', $item['batch_id'])
                    ->decrement('quantity', $item['quantity']);
            }
            DB::table('purch_returns')->insert($items);
            DB::table('stock')->insert($stocks);
            DB::table('ledgers')->insert([
                "party_type" => "provider",
                "party_id" => $invoices->provider_id,
                "transaction_type" => "debit",
                "amount" => $total_price,
                "source_type" => "purchase_return",
                "source_id" => $id,
                "description" => "Purchase return - reduced payable to provider"
            ]);
            DB::table('providers')->where('id', $invoices->provider_id)->decrement('dept', $total_price);
            return [
                'status' => true,
                'message' => 'Purchase return completed successfully',
                'invoice_id' => $id,
                'total_return' => $total_price
            ];
        });

    }
}
