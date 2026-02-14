<?php

namespace App\Services;

use DB;

const PAYMENT = "receipt_vouchers";

class ReceiptVouchersServices
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

            $payment_id = DB::table(PAYMENT)->insertGetId([...$payment, "status" => "posted"]);
            DB::table('ledgers')->insert([
                "party_type" => "customer",
                "party_id" => $payment['customer_id'],
                "transaction_type" => "credit",
                "amount" => $payment['amount'],
                "source_type" => "receipt-vouchers",
                "source_id" => $payment_id,
                "description" => "receipt_vouchers"
            ]);
            DB::table('ledgers')->insert([
                'party_type' => $payment['party_type'],
                'party_id' => $payment['party_id'],
                'transaction_type' => 'debit',
                'amount' => $payment['amount'],
                'source_type' => 'receipt_vouchers',
                'source_id' => $payment_id,
            ]);
            if ($payment['party_type'] === "cash") {
                DB::table('cash_accounts')
                    ->where('id', $payment['party_id'])
                    ->increment('balance', $payment['amount']);
            } else if ($payment['party_type'] === "bank") {
                DB::table('bank_accounts')
                    ->where('id', $payment['party_id'])
                    ->increment('balance', $payment['amount']);
            }

            DB::table('customers')
                ->where('id', $payment['customer_id'])
                ->decrement('dept', $payment['amount']);
            return $payment_id;
        });
    }


    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {
            $payment = DB::table(PAYMENT)->where('id', $id)->first();
            if ($payment->status === 'cancelled') {
                return false;
            }
            DB::table(PAYMENT)->where("id", $id)->update(["status" => "cancelled"]);
            DB::table('ledgers')->insert([
                "party_type" => "customer",
                "party_id" => $payment->customer_id,
                "transaction_type" => "debit",
                "amount" => $payment->amount,
                "source_type" => "receipt-vouchers",
                "source_id" => $id,
                "description" => "retutn_receipt_vouchers"
            ]);
            DB::table('ledgers')->insert([
                'party_type' => $payment->party_type,
                'party_id' => $payment->party_id,
                'transaction_type' => 'credit',
                'amount' => $payment->amount,
                'source_type' => 'retutn_receipt_vouchers',
                'source_id' => $id
            ]);
            if ($payment->party_type === "cash") {
                DB::table('cash_accounts')
                    ->where('id', $payment->party_id)
                    ->decrement('balance', $payment->amount);
            } else if ($payment->party_type === "bank") {
                DB::table('bank_accounts')
                    ->where('id', $payment->party_id)
                    ->decrement('balance', $payment->amount);
            }

            DB::table('customers')
                ->where('id', $payment->customer_id)
                ->increment('dept', $payment->amount);
            return $payment;
        });
    }

}
