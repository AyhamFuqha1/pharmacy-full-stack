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
        $data=$request->validated();

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

}
