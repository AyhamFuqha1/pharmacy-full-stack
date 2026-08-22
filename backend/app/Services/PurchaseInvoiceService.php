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
        return DB::transaction(function () use ($request, $user) {
            $id_purchase_invoices = DB::table('purchase_invoices')->insertGetId([
                "provider_id" => $request->provider_id,
                "user_id" => $user->id,
                'invoice_discount' => $request->invoice_discount,
                'date' => $request->date,
                'due_date' => $request->due_date,
                'status' => "draft"
            ]);
            $total_before_discount = 0;
            $total_after_discount = 0;
            $items = [];
            foreach ($request->items as $item) {
                $items[] = [
                    "purchase_invoice_id" => $id_purchase_invoices,
                    "product_id" => $item["product_id"],
                    "batch_number" => $item["batch_number"],
                    "amount" => $item["amount"],
                    "expire_date" => $item["expire_date"],
                    "purchase_price" => $item["purchase_price"],
                    "selling_price" => $item["selling_price"],
                    "discount" => $item["item_discount"]
                ];
                $total_before_discount += ($item["purchase_price"] * $item["amount"]) - $item["item_discount"];
            }
            $total_after_discount = $total_before_discount - $request->invoice_discount;
            DB::table('prod_purch')->insert($items);
            DB::table('purchase_invoices')->where('id', $id_purchase_invoices)->update([
                "total_before_discount" => $total_before_discount,
                "total_after_discount" => $total_after_discount,
            ]);
            return true;
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
                (product_id, batch_number, expiry_date, purchase_price, sale_price,initial_quantity, quantity, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    quantity = quantity + VALUES(quantity),
                     id = LAST_INSERT_ID(id)
            ", [
                    $product->product_id,
                    $product->batch_number,
                    $product->expire_date,
                    $product->purchase_price,
                    $product->selling_price,
                    $product->amount,
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
            DB::table('stock')->insert($stocks);
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
        $purchase = DB::table('purchase_invoices')->where('id', $id)->first();
        if ($purchase->status !== "draft") {
            return false;
        }
        DB::table('purchase_invoices')->where('id', $id)->update(['status' => "cancelled"]);
    }

    public function update($request, $id, $user): mixed
    {
        return DB::transaction(function () use ($request, $id, $user) {
            $purch = DB::table('purchase_invoices')->where('id', $id)->first();
            if(!$purch || $purch->status!=="draft"){
                return $purch;
            }
            $prod=DB::table("prod_purch")->latest('id')->value('id');
            //provider_id	user_id	total_before_discount	invoice_discount	total_after_discount	date	due_date	status	created_at	updated_at	

            DB::table('purchase_invoices')->where('id', $id)->update([
                'provider_id' => $request->provider_id,
                'updated_by' => $user->id,
                'due_date' => $request->due_date,
                'invoice_discount' => $request->invoice_discount
            ]);
            $items = [];
            $lastId=$prod;
            $total_before_discount = 0;
            $total_after_discount = 0;
            foreach ($request->items as $item) {
                $items[] = [
                    'id' => $item['id'] ?? ++$lastId,
                    'purchase_invoice_id' => $id,
                    'product_id' => $item['product_id'],
                    'batch_number' => $item['batch_number'],
                    'amount' => $item['amount'],
                    'purchase_price' => $item['purchase_price'],
                    'selling_price' => $item['selling_price'],
                    'discount' => $item['item_discount'] ?? 0,
                ];
                $total_before_discount += ($item["purchase_price"] * $item["amount"]) - $item["item_discount"];

            }
            $total_after_discount = $total_before_discount - $request->invoice_discount;
            DB::table('purchase_invoices')->where('id', $id)->update([
                "total_before_discount" => $total_before_discount,
                "total_after_discount" => $total_after_discount,
            ]);
            DB::table('prod_purch')->upsert(
                $items,
                ['id'],
                ['amount', 'purchase_price', 'selling_price', 'discount', 'batch_number', 'product_id']
            );
            $ids = collect($items)
                ->pluck('id')
                ->filter()
                ->toArray();
          //  return $ids;
            DB::table('prod_purch')->where('purchase_invoice_id', $id)->whereNotIn('id', $ids)->delete();
            return true;
        });
    }
}

