<?php
namespace App\Services;

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
                return [
                    'message' => "Invoice already cancelled"
                ];
            }
            DB::statement("  UPDATE product_batches pb   JOIN prod_sales ps ON ps.batch_id = pb.id  SET pb.quantity = pb.quantity + ps.amount  WHERE ps.sales_invoice_id = ?", [$id]);
            DB::table('prod_sales')->where('sales_invoice_id', $id)->update(['deleted_at' => now()]);
            DB::table('sales_invoices')->where('id', $id)->update(['deleted_at' => now()]);
            DB::table('stock')->where('reference_id', $id)->update(["deleted_at" => now()]);
            return [
                'message' => "Deleted is Done"
            ];
            ;
        });
    }
    public function store($request, $user)
    {
        return DB::transaction(function () use ($request, $user) {
            if ($request->items == 0) {
                return [
                    "message" => "items is empty"
                ];
            }
            $total = 0;
            $id_sales_invoices = DB::table('sales_invoices')->insertGetId([
                'customer_id' => $request['customer_id'],
                'user_id' => $user->id,
                'discount' => $request['discount'],
                'payment_method'=>$request['payment_method'],
                'date' => $request['date']
            ]);
            $total_before = 0;
            $total_purch = 0;
            foreach ($request->items as $item) {
                $total_before += ($item['price'] * $item['amount']) - $item['discount'];
            }
            $items = [];
            $stocks = [];
            $batchesAll = DB::table('product_batches')->whereIn('id', collect($request->items)->pluck('batch_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($request->items as $item) {
                $lineTotal = ($item['price'] * $item['amount']) - $item['discount'];
                $batches = $batchesAll[$item['batch_id']];
                $purchase_price = $batches->purchase_price;
                if ($batches->quantity < $item['amount']) {
                    throw new Exception("Insufficient stock for batch {$batches->id}");
                }
                $invoice_discount_share = $total_before > 0 ? ($request['discount'] * $lineTotal) / $total_before : 0;
                $total_purch += ($purchase_price * $item['amount']);
                $items[] = [
                    'sales_invoice_id' => $id_sales_invoices,
                    'batch_id' => $item['batch_id'],
                    'amount' => $item['amount'],
                    'price' => $item['price'],
                    'discount' => $item['discount'],
                    'cost_price' => $purchase_price,
                    'line_total' => $lineTotal,
                    'invoice_discount_share' => $invoice_discount_share,
                    'final_total' => $lineTotal - $invoice_discount_share,
                    'profit' => ($lineTotal - $invoice_discount_share) - ($purchase_price * $item['amount']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                /*if(0>(($lineTotal - $invoice_discount_share) - ($purchase_price * $item['amount']))){
                    return [
                        "message"=>"Profit in this Items {$item['batch_id']} shgeb "
                    ];
                }*/


                $stocks[] = [
                    'batch_id' => $item['batch_id'],
                    'quantity' => $item['amount'],
                    'type' => 'OUT',
                    'reference_type' => 'sale',
                    'reference_id' => $id_sales_invoices,
                    'user_id' => $user->id

                ];

                DB::table('product_batches')->where('id', $item['batch_id'])->decrement('quantity', $item['amount']);
            }
            DB::table('prod_sales')->insert($items);
            DB::table('stock')->insert($stocks);
            DB::table('sales_invoices')->where('id', $id_sales_invoices)->update([
                'total_price' => $total_before,
                'profit' => $total_before - $total_purch - $request['discount'],

            ]);


            return true;

        });
    }
    public function show($id){
      return DB::table('sales_invoices')->where('id',$id)->first();
    }
}
