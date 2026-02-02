<?php

namespace App\Services;

use DB;

class PurchaseInvoiceService
{

    public function index()
    {
        return DB::table('purchase_invoices')->get();
    }

    public function show($id)
    {
        return DB::table('purchase_invoices')->where('id', $id)->first();
    }

    public function store($request, $user)
    {
        DB::transaction(function () use ($request, $user) {
            $id_purchase_invoices = DB::table('purchase_invoices')->insertGetId([
                "provider_id" => $request->provider_id,
                "user_id" => $user->id,
                'invoice_discount' => $request->invoice_discount,
                'date' => $request->date,
                'dute_date' => $request->due_date,
                'status' => $request->status
            ]);
            $total_before_discount = 0;
            $total_after_discount = 0;
            $items = [];
            foreach ($request->items as $item) {
                $items[] = [
                    "product_id" => $item->product_id,
                    "batch_id" => $item->batch_number,
                    "amount" => $item->amount,
                    "expire_date" => $item->expire_date,
                    "purchase_price" => $item->purchase_price,
                    "selling_price" => $item->selling_price,
                    "price" => $item->purchase_price,
                    "descount" => $item->discount
                ];
                $total_before_discount += ($item->purchase_price * $item->amount) - $item->discount;
            }
            DB::table('prod_purch')->insert($items);
            DB::table('purchase_invoices')->where('id', $id_purchase_invoices)->update([
                "total_before_discount" => $total_before_discount,
                "total_after_discount" => $total_after_discount,
            ]);

        });
    }

    public function post($id, $user)
    {
        return DB::transaction(function () use ($id, $user) {
            $purchase = DB::table('purchase_invoices')->where('id', $id)->lockForUpdate()->first();
            if ($purchase->status === 'posted' || $purchase->status === 'cancelled') {
                return false;
            }
            $products = DB::table('prod_purch')->where('purchase_invoice_id', $id)->get();
            $stocks = [];//id	purchase_invoice_id	batch_id	expire_date	purchase_price	selling_price	amount	price	discount	created_at	updated_at
            foreach ($products as $product) {
                DB::statement("
                INSERT INTO product_batches
                (product_id, batch_number, expire_date, purchase_price, selling_price, quantity, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                     id = LAST_INSERT_ID(id)
            ", [
                    $product->product_id,
                    $product->batch_number,
                    $product->expire_date,
                    $product->purchase_price,
                    $product->selling_price,
                    $product->amount
                ]);

                $batchId = DB::getPdo()->lastInsertId();
                $stocks[] = [
                    'batch_id' => $batchId,
                    'quantity' => $product->amount,
                    'type' => 'IN',
                    'reference_type' => 'purchase',
                    'reference_id' => $id,
                    'user_id' => $user->id,
                    'created_at' => now()
                ];
            }
            DB::table('stocks')->insert($stocks);
            DB::table('purchase_invoices')->where('id', $id)->update([

                'status' => 'posted'
            ]);//
            DB::table('ledgers')->insert([
                "party_type" => "provider",
                "party_id" => $purchase->provider_id,
                "transaction_type" => "credit",
                "amount" => $purchase->total_after_discount,
                "source_type" => "purchase_invoice",
                "source_id" => $id,
                "description" => "Credit to provider from purchase invoice"
            ]);
            DB::table('providers')->where('id', $purchase->provider_id)->increment('dept', $purchase->total_after_discount);
            return true;
        });

    }
    public function cancel($id, $user)
    {
      $purchase= DB::table('purchase_invoices')->where('id',$id)->first();
      if($purchase->status==="draft"){
            return false;
      }
      DB::table('purcheas_invoices')->where('id',$id)->update(['status'=>"cancelled"]);
    }
}
