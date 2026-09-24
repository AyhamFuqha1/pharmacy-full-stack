<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ExpressService
{
    public function index() { return DB::table('expenses')->get(); }
    public function show($id) { return DB::table('expenses')->where('id', $id)->first(); }

    public function store($request, $user): int
    {
        return DB::transaction(function () use ($request, $user) {
            $id = DB::table('expenses')->insertGetId([
                'document_number' => app(DocumentNumberService::class)->next('expense','EXP'),
                'amount' => $request->amount,
                'type' => $request->payment_type === 'bank' ? 'bank' : 'cash',
                'payment_method' => $request->payment_type,
                'branch_id' => $request->branch_id ?? null,
                'expense_account_id' => $request->expense_account_id ?? DB::table('accounts')->where('code','5200')->value('id'),
                'description' => $request->description,
                'external_reference' => $request->external_reference ?? null,
                'notes' => $request->notes ?? $request->description,
                'date' => $request->expense_date,
                'user_id' => $user->id,
                'status' => 'posted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(AccountingPostingService::class)->postExpense($id);
            app(PrintIdentityService::class)->capture('expense', $id);
            return $id;
        });
    }

    public function destroy($id, $user = null): array
    {
        return DB::transaction(function () use ($id, $user) {
            $expense = DB::table('expenses')->where('id', $id)->lockForUpdate()->first();
            if (!$expense || $expense->status === 'cancelled') return ['status' => false, 'message' => 'Expense not found or already cancelled'];
            app(AccountingPostingService::class)->reverseEvent('expense', $expense->id, $expense->date, ($user ?? auth()->user())->id, 'Expense cancellation');
            DB::table('expenses')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            return ['status' => true, 'message' => 'Expense cancelled'];
        });
    }
}
