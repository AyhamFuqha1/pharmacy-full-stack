<?php

namespace App\Services;

use DB;

class CustomerService
{

    public function index()
    {
        return DB::table('customers')->get();
    }

    public function show($id)
    {
        return DB::table("customers")->where("id", $id)->first();
    }

    public function store($customer)
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => $customer['name'],
            'phone_num' => $customer['phone_num'],
            'city' => $customer['city'],
        ]);
    }

    public function update($request, $id)
    {
        $customer = DB::table('customers')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$customer) {
            return false;
        }
        $data = $request->validated();

        DB::table('customers')
            ->where('id', $id)
            ->update($data);

        return true;
    }

    public function destory($id)
    {
        $customer = DB::table('customers')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$customer) {
            return false;
        }

        DB::table('customers')
            ->where('id', $id)
            ->update([
                "deleted_at" => now()
            ]);
        return true;
    }

    public function incrementDept($id, $amount, $id_sales_invoices)
    {
        $customer = DB::table("customers")->where('id', $id)->first();
        if (!$customer) {
            return false;
        }
        DB::table("customers")->where('id', $id)->increment('dept', $amount);
        DB::table('ledgers')->insert([
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'transaction_type' => 'debit',
            'amount' => $amount,
            'source_type' => 'invoice',
            'source_id' => $id_sales_invoices,
            'description' => "Payment due from invoice #{$id_sales_invoices}"
        ]);
        return true;
    }
    public function decrementDept($id, $amount, $id_sales_invoices)
    {
        $customer = DB::table("customers")->where('id', $id)->first();
        if (!$customer) {
            return false;
        }



        DB::table("customers")->where('id', $id)->decrement('dept', $amount);


        DB::table('ledgers')->insert([
            'party_type' => 'customer',
            'party_id' => $customer->id,
            'transaction_type' => 'credit',
            'amount' => $amount,
            'source_type' => 'receipt',
            'source_id' => $id_sales_invoices,
            'description' => "Payment received for invoice #{$id_sales_invoices}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }


}
