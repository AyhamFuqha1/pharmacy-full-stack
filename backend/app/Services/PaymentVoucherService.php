<?php

namespace App\Services;

use DB;

const PAYMENT = 'payment_vouchers';
class PaymentVoucherService
{
    public function index()
    {
        return DB::table(PAYMENT)->get();
    }
    public function show($id)
    {
        return DB::table(PAYMENT)->where('id', $id)->first();
    }
    public function store($payment)
    {
        return DB::transaction(function () use ($payment) {

            $payment_id = DB::table(PAYMENT)->insertGetId([...$payment,'status'=>"posted"]);
            DB::table('ledgers')->insert([
                "party_type" => "provider",
                "party_id" => $payment['provider_id'],
                "transaction_type" => "debit",
                "amount" => $payment['amount'],
                "source_type" => "payment_voucher",
                "source_id" => $payment_id,
                "description" => "payment_voucher"
            ]);
            DB::table('ledgers')->insert([
                'party_type' => 'cash',
                'party_id' => $payment['cash_id'],
                'transaction_type' => 'credit',
                'amount' => $payment['amount'],
                'source_type' => 'payment_voucher',
                'source_id' => $payment['cash_id'],
            ]);
            DB::table('cash_accounts')
                ->where('id', $payment['cash_id'])
                ->decrement('balance', $payment['amount']);

            DB::table('providers')
                ->where('id', $payment['provider_id'])
                ->decrement('dept', $payment['amount']);
            return $payment_id;
        });
    }
    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = DB::table(PAYMENT)->where('id', $id)->first();
            if (!$payment || $payment->status === "canceled") {
                return false;
            }
            DB::table('ledgers')->insert([
                "party_type" => "provider",
                "party_id" => $payment->provider_id,
                "transaction_type" => "credit",
                "amount" => $payment->amount,
                "source_type" => "payment_voucher",
                "source_id" => $id,
                "description" => "payment_voucher"
            ]);
            DB::table('ledgers')->insert([
                'party_type' => 'cash',
                'party_id' => $payment->cash_id,
                'transaction_type' => 'debit',
                'amount' => $payment->amount,
                'source_type' => 'payment_voucher',
                'source_id' => $id,
            ]);
            DB::table('cash_accounts')
                ->where('id', $payment->cash_id)
                ->increment('balance', $payment->amount);

            DB::table('providers')
                ->where('id', $payment->provider_id)
                ->increment('dept', $payment->amount);

            DB::table(PAYMENT)->where('id', $id)->update([
                'status' => "cancelled"
            ]);
            return $id;
        });


    }
}
