<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PaymentVoucherService
{
    public function index() { return DB::table('payment_vouchers')->get(); }
    public function show($id) { return DB::table('payment_vouchers')->where('id', $id)->first(); }

    public function store($payment, $user = null): int
    {
        return DB::transaction(function () use ($payment, $user) {
            $id = DB::table('payment_vouchers')->insertGetId([
                'document_number' => app(DocumentNumberService::class)->next('payment','PAY'),
                'provider_id' => $payment['provider_id'], 'user_id' => ($user ?? auth()->user())->id,
                'amount' => $payment['amount'], 'payment_method' => $payment['payment_method'], 'date' => $payment['date'], 'status' => 'posted',
            ]);
            if ($payment['payment_method'] === 'cheque') {
                app(ChequeService::class)->createLinked([
                    'cheque_number' => $payment['cheque_number'], 'bank' => $payment['bank'], 'due_date' => $payment['due_date'],
                    'amount' => $payment['amount'], 'direction' => 'outgoing', 'provider_id' => $payment['provider_id'], 'payment_voucher_id' => $id,
                ]);
            }
            app(AccountingPostingService::class)->postPaymentVoucher($id);
            app(SettlementAllocationService::class)->allocatePayment($id, $payment['allocations'] ?? [], ($user ?? auth()->user())->id);
            app(PrintIdentityService::class)->capture('supplier_payment', $id);
            return $id;
        });
    }

    public function destroy($id, $user = null): bool
    {
        return DB::transaction(function () use ($id, $user) {
            $payment = DB::table('payment_vouchers')->where('id', $id)->lockForUpdate()->first();
            if (!$payment || $payment->status === 'cancelled') return false;
            if (DB::table('checks')->where('payment_voucher_id', $id)->exists()) return false;
            app(AccountingPostingService::class)->reverseEvent('payment', $payment->id, $payment->date, ($user ?? auth()->user())->id, 'Payment voucher cancellation');
            app(SettlementAllocationService::class)->remove('payment', $payment->id);
            DB::table('payment_vouchers')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            return true;
        });
    }
}
