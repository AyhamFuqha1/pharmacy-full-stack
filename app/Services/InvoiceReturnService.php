<?php

namespace App\Services;

use DB;
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
}
