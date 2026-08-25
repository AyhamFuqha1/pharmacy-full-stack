<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Stock;
use DB;
use Illuminate\Support\Facades\Log;

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
                'provider_id' => $request->provider_id,
                'user_id' => $user->id,
                'invoice_discount' => $request->invoice_discount ?? 0,
                'date' => $request->date,
                'due_date' => $request->due_date,
                'status' => 'draft',
            ]);

            $total_before_discount = 0;
            $items = [];

            foreach ($request->items as $item) {
                $lineTotal = ($item['purchase_price'] * $item['amount']) - ($item['item_discount'] ?? 0);
                $total_before_discount += $lineTotal;

                $items[] = [
                    'purchase_invoice_id' => $id_purchase_invoices,
                    'product_id' => $item['product_id'],
                    'batch_number' => $item['batch_number'],
                    'amount' => $item['amount'],
                    'expire_date' => $item['expire_date'],
                    'purchase_price' => $item['purchase_price'],
                    'selling_price' => $item['selling_price'],
                    'discount' => $item['item_discount'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $total_after_discount = $total_before_discount - ($request->invoice_discount ?? 0);

            DB::table('prod_purch')->insert($items);
            DB::table('purchase_invoices')->where('id', $id_purchase_invoices)->update([
                'total_before_discount' => $total_before_discount,
                'total_after_discount' => $total_after_discount,
            ]);

            return true;
        });
    }

    public function post($id, $user)
    {
        return DB::transaction(function () use ($id, $user) {
            $purchase = DB::table('purchase_invoices')->where('id', $id)->lockForUpdate()->first();
            if (!$purchase || in_array($purchase->status, ['posted', 'cancelled'], true)) {
                return false;
            }

            $products = DB::table('prod_purch')->where('purchase_invoice_id', $id)->get();

            foreach ($products as $product) {
                $batch = ProductBatch::query()
                    ->where('product_id', $product->product_id)
                    ->where('batch_number', $product->batch_number)
                    ->whereDate('expiry_date', $product->expire_date)
                    ->lockForUpdate()
                    ->first();

                if (!$batch) {
                    $batch = ProductBatch::query()->create([
                        'product_id' => $product->product_id,
                        'batch_number' => $product->batch_number,
                        'expiry_date' => $product->expire_date,
                        'quantity' => 0,
                        'purchase_price' => $product->purchase_price,
                        'sale_price' => $product->selling_price,
                    ]);
                }

                $batch->applyPurchase((int) $product->amount, (float) $product->purchase_price);
                $batch->refresh();
            }

            DB::table('purchase_invoices')->where('id', $id)->update(['status' => 'posted']);

            return true;
        });
    }

    public function cancel($id, $user)
    {
        $purchase = DB::table('purchase_invoices')->where('id', $id)->first();
        if ($purchase->status !== 'draft') {
            return false;
        }

        DB::table('purchase_invoices')->where('id', $id)->update(['status' => 'cancelled']);
        return true;
    }

    public function update($request, $id, $user): mixed
    {
        return DB::transaction(function () use ($request, $id, $user) {
            $purch = DB::table('purchase_invoices')->where('id', $id)->first();
            if (!$purch || $purch->status !== 'draft') {
                return $purch;
            }

            $prod = DB::table('prod_purch')->latest('id')->value('id');

            DB::table('purchase_invoices')->where('id', $id)->update([
                'provider_id' => $request->provider_id,
                'updated_by' => $user->id,
                'due_date' => $request->due_date,
                'invoice_discount' => $request->invoice_discount ?? 0,
            ]);

            $items = [];
            $lastId = $prod;
            $total_before_discount = 0;

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
                $total_before_discount += ($item['purchase_price'] * $item['amount']) - ($item['item_discount'] ?? 0);
            }

            $total_after_discount = $total_before_discount - ($request->invoice_discount ?? 0);

            DB::table('purchase_invoices')->where('id', $id)->update([
                'total_before_discount' => $total_before_discount,
                'total_after_discount' => $total_after_discount,
            ]);

            DB::table('prod_purch')->upsert(
                $items,
                ['id'],
                ['amount', 'purchase_price', 'selling_price', 'discount', 'batch_number', 'product_id']
            );

            $ids = collect($items)->pluck('id')->filter()->toArray();
            DB::table('prod_purch')->where('purchase_invoice_id', $id)->whereNotIn('id', $ids)->delete();

            return true;
        });
    }
}

