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
            ->join('products', 'product_batches.product_id', '=', 'products.id')->whereRaw('CURDATE() > product_batches.expiry_date');


        $ranges = DB::table('product_batches')
            ->leftJoin('prod_sales', 'product_batches.id', '=', 'prod_sales.batch_id')
            ->leftJoin('products', 'product_batches.product_id', '=', 'products.id')
            ->select(
                'products.name',
                'product_batches.batch_number',
                DB::raw('COALESCE(SUM(prod_sales.amount),0) as sold_qty'),
                DB::raw('GREATEST(DATEDIFF(CURDATE(), product_batches.created_at),1) as days_passed'),
                DB::raw('DATEDIFF(product_batches.expiry_date, CURDATE()) as days_remaining'),
                'product_batches.initial_quantity',
                DB::raw('
            (
                (COALESCE(SUM(prod_sales.amount),0) /
                GREATEST(DATEDIFF(CURDATE(), product_batches.created_at),1))
                *
                (DATEDIFF(CURDATE(), product_batches.created_at) +
                DATEDIFF(product_batches.expiry_date, CURDATE()))
            ) / product_batches.initial_quantity
            as sell_ratio
        '),
                DB::raw('CASE 
            WHEN DATEDIFF(CURDATE(), product_batches.created_at) < 7 THEN "NEW"
            WHEN (
                (
                    (COALESCE(SUM(prod_sales.amount),0) /
                    GREATEST(DATEDIFF(CURDATE(), product_batches.created_at),1))
                    *
                    (DATEDIFF(CURDATE(), product_batches.created_at) +
                    DATEDIFF(product_batches.expiry_date, CURDATE()))
                ) / product_batches.initial_quantity
            ) < 0.5 THEN "HIGH"
            WHEN (
                (
                    (COALESCE(SUM(prod_sales.amount),0) /
                    GREATEST(DATEDIFF(CURDATE(), product_batches.created_at),1))
                    *
                    (DATEDIFF(CURDATE(), product_batches.created_at) +
                    DATEDIFF(product_batches.expiry_date, CURDATE()))
                ) / product_batches.initial_quantity
            ) < 0.7 THEN "MEDIUM"
            ELSE "SAFE"
        END as risk')
            )
            ->groupBy(
                'product_batches.id',
                'products.name',
                'product_batches.batch_number',
                'product_batches.initial_quantity',
                'product_batches.created_at',
                'product_batches.expiry_date'
            )
            ->get();



    }  
}
