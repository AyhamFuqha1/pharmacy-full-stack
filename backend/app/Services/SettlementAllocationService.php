<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettlementAllocationService
{
    public function allocateReceipt(int $id, array $requested, int $actor): void
    {
        $s = DB::table('receipt_vouchers')->where('id', $id)->lockForUpdate()->first();
        $this->allocate('customer', 'receipt', $id, (int) $s->customer_id, (float) $s->amount, $requested, $actor);
    }

    public function allocatePayment(int $id, array $requested, int $actor): void
    {
        $s = DB::table('payment_vouchers')->where('id', $id)->lockForUpdate()->first();
        $this->allocate('supplier', 'payment', $id, (int) $s->provider_id, (float) $s->amount, $requested, $actor);
    }

    public function remove(string $type, int $id): void
    {
        DB::table('settlement_allocations')->where(['settlement_type' => $type, 'settlement_id' => $id])->delete();
    }

    private function allocate(string $side, string $type, int $settlementId, int $party, float $amount, array $requested, int $actor): void
    {
        $invoiceTable = $side === 'customer' ? 'sales_invoices' : 'purchase_invoices';
        $invoiceType = $side === 'customer' ? 'sale' : 'purchase';
        $partyColumn = $side === 'customer' ? 'customer_id' : 'provider_id';
        $query = DB::table($invoiceTable)->where($partyColumn, $party)->orderBy('date')->orderBy('id');
        if ($side === 'customer') $query->whereNull('deleted_at'); else $query->where('status', 'posted');
        $invoices = $query->lockForUpdate()->select('id','date')->selectRaw($side === 'customer' ? 'total_price as original_amount' : 'COALESCE(NULLIF(total_after_discount,0),amount) as original_amount')->get();
        $outstanding = [];
        foreach ($invoices as $invoice) {
            $allocated = (float) DB::table('settlement_allocations')->where(['invoice_type'=>$invoiceType,'invoice_id'=>$invoice->id])->sum('amount');
            $returns = $side === 'customer'
                ? (float) DB::table('invoice_returns')->where('invoice_id',$invoice->id)->selectRaw('COALESCE(SUM(quantity * COALESCE(customer_credit_unit_amount, price)), 0) as total')->value('total')
                : (float) DB::table('purch_returns')->where('invoice_id',$invoice->id)->sum('line_total_return');
            $paidAtSource = $side === 'customer' ? (float)DB::table('sales_invoices')->where('id',$invoice->id)->value('paid_amount') : 0.0;
            $outstanding[$invoice->id] = max(0, round((float)$invoice->original_amount - $returns - $allocated - $paidAtSource, 2));
        }
        $allocations = $requested;
        if (!$allocations) {
            $remaining = $amount;
            foreach ($invoices as $invoice) {
                $value = min($remaining, $outstanding[$invoice->id]);
                if ($value > 0) $allocations[] = ['invoice_id'=>$invoice->id,'amount'=>$value];
                $remaining = round($remaining - $value, 2);
                if ($remaining <= 0) break;
            }
        }
        $total = round(array_sum(array_map(fn($x)=>(float)$x['amount'], $allocations)), 2);
        if ($total > round($amount,2)) throw ValidationException::withMessages(['allocations'=>__('api.allocated_total_exceeds_settlement')]);
        foreach ($allocations as $allocation) {
            $invoiceId = (int)$allocation['invoice_id']; $value = round((float)$allocation['amount'],2);
            if (!array_key_exists($invoiceId,$outstanding) || $value <= 0 || $value > $outstanding[$invoiceId])
                throw ValidationException::withMessages(['allocations'=>__('api.allocation_exceeds_outstanding')]);
            DB::table('settlement_allocations')->insert(['side'=>$side,'settlement_type'=>$type,'settlement_id'=>$settlementId,'invoice_type'=>$invoiceType,'invoice_id'=>$invoiceId,'amount'=>$value,'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        }
    }
}
