<?php

namespace App\Services;

use Carbon\Carbon;
use DB;

class DashBoardService
{

    public function index()
    {
        $notification = DB::table('notification')->get();
        $today = Carbon::today()->toDateString();
        $expire_date = DB::table('product_batches')
            ->join('products', 'product_batches.product_id', '=', 'products.id')
            ->whereRaw(
                'product_batches.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL products.expiry_alert_days DAY)'
            )
            ->get();

        $min_stock = DB::table('product_batches')->join('products', 'product_batches.product_id', '=', 'products.id')->whereRa
        ('product_batches.quantity < products.min_stock_alert')->group_by('product_id');


        $expire_date = DB::table('product_batches')
            ->join('products', 'product_batches.product_id', '=', 'products.id')->whereRaw('CURDATE() > product_batches.expiry_dateww');


        $range = DB::table('product_batches')->leftJoin('prod_sales', 'product_batches.id ', '=', 'prod_sales.batch_id')->
            leftJoin('products', 'product_batches.product_id', '=', 'products.id')->
            select(
                'products.name',
                'product_batches.batch_number',
                DB::raw('SUM(prod_sales.amount) as sold_qty'),
                DB::raw('DATEDIFF(CURDATE(),product_batches.created_at) as days_passed'),
                DB::raw('DATEDIFF(CURDATE(),product_batches.expiry_date)'),
                'product_batches.quantity as amount',
            )
            ->groupBy(
                'product_batches.id',
                'products.name',
                'product_batches.batch_number',
                'product_batches.quantity',
                'product_batches.created_at',
                'product_batches.expiry_date'
            );
    }
}
