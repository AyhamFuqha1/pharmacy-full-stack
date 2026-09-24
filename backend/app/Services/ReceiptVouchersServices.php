<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReceiptVouchersServices
{
    public function index() { return DB::table('receipt_vouchers')->get(); }
    public function show($id) { return DB::table('receipt_vouchers')->where('id', $id)->first(); }

    public function store($receipt, $user = null): int
    {
        return DB::transaction(function () use ($receipt, $user) {
            $id = DB::table('receipt_vouchers')->insertGetId([
                'document_number' => app(DocumentNumberService::class)->next('receipt','REC'),
                'customer_id' => $receipt['customer_id'], 'user_id' => ($user ?? auth()->user())->id,
                'amount' => $receipt['amount'], 'payment_method' => $receipt['payment_method'], 'date' => $receipt['date'], 'status' => 'posted',
            ]);
            if ($receipt['payment_method'] === 'cheque') {
                app(ChequeService::class)->createLinked([
                    'cheque_number' => $receipt['cheque_number'], 'bank' => $receipt['bank'], 'due_date' => $receipt['due_date'],
                    'amount' => $receipt['amount'], 'direction' => 'incoming', 'customer_id' => $receipt['customer_id'], 'receipt_voucher_id' => $id,
                ]);
            }
            app(AccountingPostingService::class)->postReceiptVoucher($id);
            app(SettlementAllocationService::class)->allocateReceipt($id, $receipt['allocations'] ?? [], ($user ?? auth()->user())->id);
            app(PrintIdentityService::class)->capture('customer_receipt', $id);
            return $id;
        });
    }

    public function destroy($id, $user = null): bool
    {
        return DB::transaction(function () use ($id, $user) {
            $receipt = DB::table('receipt_vouchers')->where('id', $id)->lockForUpdate()->first();
            if (!$receipt || $receipt->status === 'cancelled') return false;
            if (DB::table('checks')->where('receipt_voucher_id', $id)->exists()) return false;
            app(AccountingPostingService::class)->reverseEvent('receipt', $receipt->id, $receipt->date, ($user ?? auth()->user())->id, 'Receipt voucher cancellation');
            app(SettlementAllocationService::class)->remove('receipt', $receipt->id);
            DB::table('receipt_vouchers')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            return true;
        });
    }
}
