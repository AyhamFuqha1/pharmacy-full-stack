<?php

namespace App\Services;

use DB;

class ExpenseService
{
    const TABLE = 'expenses';

    public function index()
    {
        return DB::table(self::TABLE)->get();
    }

    public function show($id)
    {
        return DB::table(self::TABLE)->where('id', $id)->first();
    }

    public function store($request, $user)
    {
        return DB::transaction(function () use ($request, $user) {

            $expenseId = DB::table(self::TABLE)->insertGetId([
                'amount' => $request->amount,
                'category' => $request->category,
                'type' => $request->payment_type,
                'account_id' => $request->account_id,
                'notes' => $request->notes,
                'date' => $request->expense_date,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);

            DB::table('ledgers')->insert([
                'party_type' => 'expense',
                'party_id' => $expenseId,
                'transaction_type' => 'debit',
                'amount' => $request->amount,
                'source_type' => 'expense',
                'source_id' => $expenseId,
                'description' => 'Expense: ' . $request->category,
                'created_at' => now(),
            ]);



            $this->decreaseBalance(
                $request->payment_type,
                $request->account_id,
                $request->amount
            );

            return $expenseId;
        });
    }

    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {

            $expense = DB::table(self::TABLE)->where('id', $id)->first();

            if (!$expense) {
                throw new \Exception("Expense not found");
            }


            DB::table('ledgers')
                ->where('reference_type', 'expense')
                ->where('reference_id', $id)
                ->delete();


            $this->increaseBalance(
                $expense->payment_type,
                $expense->account_id,
                $expense->amount
            );


            DB::table(self::TABLE)->where('id', $id)->delete();
        });
    }



    private function decreaseBalance($type, $accountId, $amount)
    {
        $table = $this->resolveAccountTable($type);

        DB::table($table)
            ->where('id', $accountId)
            ->decrement('balance', $amount);
    }

    private function increaseBalance($type, $accountId, $amount)
    {
        $table = $this->resolveAccountTable($type);

        DB::table($table)
            ->where('id', $accountId)
            ->increment('balance', $amount);
    }

    private function resolveAccountTable($type)
    {
        return match ($type) {
            'cash' => 'cash_accounts',
            'bank' => 'bank_accounts',
            'check' => 'checks',
            default => throw new \Exception('Invalid payment type'),
        };
    }
}
