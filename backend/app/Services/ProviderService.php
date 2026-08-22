<?php

namespace App\Services;

use DB;

class ProviderService
{
    public function index()
    {
        return DB::table('providers')->get();
    }

    public function show($id)
    {
        return DB::table('providers')->where('id', $id)->first();
    }

    public function store($request)
    {
        return DB::transaction(function () use ($request) {
            $id = DB::table('providers')->insertGetId([
                'name' => $request['name'],
                'phone_num' => $request['phone_num'],
                'city' => $request['city'],
            ]);
            if ($request->filled('dept')) {
                DB::table('ledgers')->insert([
                    'party_type' => 'supplier',
                    'party_id' => $id,
                    'transaction_type' => 'debit',
                    'amount' => $request->dept,
                    'source_type' => 'purchase',
                    'source_id' => 0,
                    'description' => "Opening balance for provider",
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            return true;
        });


    }

    public function update($request, $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $provider = DB::table('providers')->where('id', $id)->first();
            if (!$provider)
                return false;
            DB::table('providers')->where('id', $id)->update($request->validated());
            return true;
        });

    }

    public function destroy($id)
    {
        $provider = DB::table('providers')->where('id', $id)->first();
        if (!$provider)
            return false;

        DB::table('providers')->where('id', $id)->update(['deleted_at' => now()]);
        return true;
    }

    // Purchase on credit → we owe supplier
    public function incrementDept($id, $amount, $purchaseId)
    {
        $provider = DB::table('providers')->where('id', $id)->first();
        if (!$provider)
            return false;

        DB::table('providers')->where('id', $id)->increment('dept', $amount);

        DB::table('ledgers')->insert([
            'party_type' => 'supplier',
            'party_id' => $provider->id,
            'transaction_type' => 'debit',
            'amount' => $amount,
            'source_type' => 'purchase',
            'source_id' => $purchaseId,
            'description' => "Amount owed for purchase #{$purchaseId}",
            'created_at' => now(),
            'updated_at' => now()
        ]);
        return true;
    }

    // Paying supplier
    public function decrementDept($id, $amount, $purchaseId)
    {
        $provider = DB::table('providers')->where('id', $id)->first();
        if (!$provider)
            return false;

        DB::table('providers')->where('id', $id)->decrement('dept', $amount);

        DB::table('ledgers')->insert([
            'party_type' => 'supplier',
            'party_id' => $provider->id,
            'transaction_type' => 'credit',
            'amount' => $amount,
            'source_type' => 'payment',
            'source_id' => $purchaseId,
            'description' => "Payment sent for purchase #{$purchaseId}",
            'created_at' => now(),
            'updated_at' => now()
        ]);
        return true;
    }
}
