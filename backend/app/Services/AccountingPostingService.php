<?php

namespace App\Services;

use App\Exceptions\AccountingPeriodException;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AccountingPostingService
{
    public function postPurchaseInvoice(int $invoiceId): JournalEntry
    {
        return $this->postEvent(
            'purchase_invoice',
            'purchase_invoices',
            $invoiceId,
            'Inventory purchase',
            fn ($event) => (float) ((float) ($event->total_after_discount ?? 0) > 0 ? $event->total_after_discount : $event->amount),
            $this->accountId('1400'),
            $this->accountId('2100'),
            'debit',
            'credit'
        );
    }

    public function postSalesInvoice(int $invoiceId): JournalEntry
    {
        return DB::transaction(function () use ($invoiceId) {
            $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (!$invoice) {
                throw new RuntimeException('Sales invoice not found.');
            }

            $salesAmount = (float) $invoice->total_price;
            $costAmount = round($salesAmount - (float) $invoice->profit, 2);
            $this->assertAmount($salesAmount);
            $this->assertAmount($costAmount);

            $existing = $this->existingEntry('sales_invoice', $invoiceId);
            if ($existing) {
                return $existing;
            }

            $period = $this->periodFor($invoice->date);
            $voucher = $this->createVoucher('sales_invoice', $invoiceId, $invoice->user_id, $period, 'Sales invoice');
            $entry = $this->createEntry($voucher, $period, $invoice->date, $invoice->user_id, 'Sales invoice');
            $paidAmount = round((float) ($invoice->paid_amount ?? 0), 2);
            if ($paidAmount < 0 || $paidAmount > $salesAmount) {
                throw new InvalidArgumentException('Paid amount must be between zero and the invoice total.');
            }
            if ($paidAmount > 0) {
                $cashOrBank = match ($invoice->payment_method ?? 'cash') {'cheque'=>'1500','visa','bank'=>'1200',default=>'1100'};
                $this->addLine($entry, $this->accountId($cashOrBank), 'debit', $paidAmount, 'Amount collected at sale');
            }
            if ($salesAmount > $paidAmount) {
                $this->addLine($entry, $this->accountId('1300'), 'debit', $salesAmount - $paidAmount, 'Outstanding receivable');
            }
            $this->addLine($entry, $this->accountId('4100'), 'credit', $salesAmount, 'Sales revenue');
            $this->addLine($entry, $this->accountId('5100'), 'debit', $costAmount, 'Cost of goods sold');
            $this->addLine($entry, $this->accountId('1400'), 'credit', $costAmount, 'Inventory cost');

            $entry = $this->finalize($entry);
            $this->recordCashMovements($entry, 'sales_invoice', $invoiceId, (int)$invoice->user_id, $invoice->branch_id ?? null);
            return $entry;
        });
    }

    public function postReceiptVoucher(int $receiptId): JournalEntry
    {
        $receipt = DB::table('receipt_vouchers')->where('id', $receiptId)->first();
        if (!$receipt) throw new RuntimeException('Receipt voucher not found.');
        return $this->postLines('receipt', $receiptId, $receipt->date, $receipt->user_id, 'Customer receipt', [
            ['code' => $receipt->payment_method === 'cheque' ? '1500' : '1100', 'side' => 'debit', 'amount' => $receipt->amount, 'description' => $receipt->payment_method === 'cheque' ? 'Incoming cheque pending' : 'Cash received'],
            ['code' => '1300', 'side' => 'credit', 'amount' => $receipt->amount, 'description' => 'Customer receivable settled'],
        ]);
    }

    public function postPaymentVoucher(int $paymentId): JournalEntry
    {
        $payment = DB::table('payment_vouchers')->where('id', $paymentId)->first();
        if (!$payment) throw new RuntimeException('Payment voucher not found.');
        return $this->postLines('payment', $paymentId, $payment->date, $payment->user_id, 'Supplier payment', [
            ['code' => '2100', 'side' => 'debit', 'amount' => $payment->amount, 'description' => 'Supplier payable settled'],
            ['code' => $payment->payment_method === 'cheque' ? '2200' : '1100', 'side' => 'credit', 'amount' => $payment->amount, 'description' => $payment->payment_method === 'cheque' ? 'Outgoing cheque pending' : 'Cash paid'],
        ]);
    }

    public function postOtherIncome(int $id): JournalEntry
    {
        $income=DB::table('other_incomes')->where('id',$id)->first();
        if(!$income)throw new RuntimeException('Other income not found.');
        return $this->postLines('other_income',$id,$income->date,$income->user_id,'Other income',[
            ['code'=>$income->payment_method==='bank'?'1200':'1100','side'=>'debit','amount'=>$income->amount,'description'=>'Income received'],
            ['account_id'=>$income->account_id,'side'=>'credit','amount'=>$income->amount,'description'=>$income->description],
        ]);
    }

    public function postChequeTransition(object $cheque, string $transition, int $userId): JournalEntry
    {
        $incoming = $cheque->direction === 'in';
        $lines = match ([$incoming, $transition]) {
            [true, 'clear'] => [
                ['code' => '1200', 'side' => 'debit', 'amount' => $cheque->amount, 'description' => 'Incoming cheque cleared to bank'],
                ['code' => '1500', 'side' => 'credit', 'amount' => $cheque->amount, 'description' => 'Cheque receivable cleared'],
            ],
            [true, 'bounce'] => [
                ['code' => '1300', 'side' => 'debit', 'amount' => $cheque->amount, 'description' => 'Customer receivable restored'],
                ['code' => '1500', 'side' => 'credit', 'amount' => $cheque->amount, 'description' => 'Incoming cheque bounced'],
            ],
            [false, 'clear'] => [
                ['code' => '2200', 'side' => 'debit', 'amount' => $cheque->amount, 'description' => 'Cheque payable cleared'],
                ['code' => '1200', 'side' => 'credit', 'amount' => $cheque->amount, 'description' => 'Outgoing cheque cleared from bank'],
            ],
            [false, 'bounce'] => [
                ['code' => '2200', 'side' => 'debit', 'amount' => $cheque->amount, 'description' => 'Outgoing cheque liability reversed'],
                ['code' => '2100', 'side' => 'credit', 'amount' => $cheque->amount, 'description' => 'Supplier payable restored'],
            ],
            default => throw new InvalidArgumentException('Unsupported cheque transition.'),
        };
        return $this->postLines('cheque_'.$transition, (int) $cheque->id, now()->toDateString(), $userId, 'Cheque '.$transition, $lines);
    }

    public function postPurchaseReturn(int $returnId): JournalEntry
    {
        return DB::transaction(function () use ($returnId) {
            $return = DB::table('purch_returns')->where('id', $returnId)->lockForUpdate()->first();
            $invoice = $return ? DB::table('purchase_invoices')->where('id', $return->invoice_id)->lockForUpdate()->first() : null;
            if (!$invoice || !$return) {
                throw new RuntimeException('Purchase return event not found.');
            }
            $supplierCredit = round((float) $return->line_total_return, 2);
            $originalUnitCost = DB::table('prod_purch')->where('id', $return->invoice_item_id)->value('purchase_price')
                ?? DB::table('prod_purch')->where('id', $return->invoice_item_id)->value('price')
                ?? $return->cost_price;
            $historicalInventoryCost = round((float) $originalUnitCost * (int) $return->quantity, 2);
            $inventoryAdjustment = round($historicalInventoryCost - $supplierCredit, 2);
            $this->assertAmount($historicalInventoryCost);
            if ($supplierCredit < 0 || $inventoryAdjustment < 0) throw new RuntimeException('Invalid purchase return credit.');

            $lines = [];
            if ($supplierCredit > 0) $lines[] = ['code' => '2100', 'side' => 'debit', 'amount' => $supplierCredit, 'description' => 'Supplier credit / accounts payable reduction'];
            if ($inventoryAdjustment > 0) $lines[] = ['code' => '5300', 'side' => 'debit', 'amount' => $inventoryAdjustment, 'description' => 'Purchase return credit shortfall'];
            $lines[] = ['code' => '1400', 'side' => 'credit', 'amount' => $historicalInventoryCost, 'description' => 'Historical inventory cost removed'];
            return $this->postLines('purchase_return', $returnId, substr((string) $return->created_at, 0, 10), $invoice->user_id, 'Purchase return', $lines);
        });
    }

    public function postSalesReturn(int $returnId): JournalEntry
    {
        return DB::transaction(function () use ($returnId) {
            $return = DB::table('invoice_returns')->where('id', $returnId)->lockForUpdate()->first();
            $invoice = $return ? DB::table('sales_invoices')->where('id', $return->invoice_id)->lockForUpdate()->first() : null;
            if (!$invoice || !$return) {
                throw new RuntimeException('Sales return event not found.');
            }
            $originalUnitAmount = $return->original_returnable_unit_amount ?? $return->price;
            $creditUnitAmount = $return->customer_credit_unit_amount ?? $return->price;
            $revenueAmount = round((float) $return->quantity * (float) $originalUnitAmount, 2);
            $customerCreditAmount = round((float) $return->quantity * (float) $creditUnitAmount, 2);
            $deductionAmount = round($revenueAmount - $customerCreditAmount, 2);
            $costAmount = round((float) (DB::table('prod_sales')
                ->where('sales_invoice_id', $return->invoice_id)
                ->where('batch_id', $return->batch_id)
                ->value('cost_price') ?? 0) * (int) $return->quantity, 2);
            $this->assertAmount($revenueAmount);
            $this->assertAmount($costAmount);
            $lines = [
                ['code' => '4100', 'side' => 'debit', 'amount' => $revenueAmount, 'description' => 'Sales revenue reversal'],
                ['code' => ($return->return_to_inventory ?? true) ? '1400' : '5300', 'side' => 'debit', 'amount' => $costAmount, 'description' => ($return->return_to_inventory ?? true) ? 'Inventory restoration' : 'Non-restock sales return cost'],
                ['code' => '5100', 'side' => 'credit', 'amount' => $costAmount, 'description' => 'COGS reversal'],
            ];
            if ($customerCreditAmount > 0) $lines[] = ['code' => '1300', 'side' => 'credit', 'amount' => $customerCreditAmount, 'description' => 'Customer return credit'];
            if ($deductionAmount > 0) $lines[] = ['code' => '4200', 'side' => 'credit', 'amount' => $deductionAmount, 'description' => 'Sales return deduction / fee'];
            return $this->postLines('sales_return', $returnId, substr((string) $return->created_at, 0, 10), $invoice->user_id, 'Sales return', $lines);
        });
    }

    public function postExpense(int $expenseId): JournalEntry
    {
        return DB::transaction(function () use ($expenseId) {
            $expense = DB::table('expenses')->where('id', $expenseId)->lockForUpdate()->first();
            if (!$expense) {
                throw new RuntimeException('Expense event not found.');
            }

            $amount = round((float) $expense->amount, 2);
            $this->assertAmount($amount);
            $cashOrBank = ($expense->type ?? null) === 'bank' ? '1200' : '1100';
            $expenseCode = $expense->expense_account_id ? DB::table('accounts')->where('id',$expense->expense_account_id)->where('account_type','expense')->value('code') : null;
            $expenseCode = $expenseCode ?: '5200';

            return $this->postLines('expense', $expenseId, $expense->date ?: $expense->created_at, $expense->user_id, 'Operating expense', [
                ['code' => $expenseCode, 'side' => 'debit', 'amount' => $amount, 'description' => 'Operating expense'],
                ['code' => $cashOrBank, 'side' => 'credit', 'amount' => $amount, 'description' => 'Cash or bank reduction'],
            ]);
        });
    }

    /** Create one immutable reversal entry for an already-posted source event. */
    public function reverseEvent(string $sourceType, int $sourceId, string $date, int $userId, string $description): ?JournalEntry
    {
        return DB::transaction(function () use ($sourceType, $sourceId, $date, $userId, $description) {
            $original = $this->existingEntry($sourceType, $sourceId);
            if (!$original) {
                return null;
            }

            $reversalSource = $sourceType . '_reversal';
            if ($this->existingEntry($reversalSource, $sourceId)) {
                return $this->existingEntry($reversalSource, $sourceId);
            }

            $period = $this->periodFor($date);
            $voucher = $this->createVoucher($reversalSource, $sourceId, $userId, $period, $description, 'journal_adjustment');
            $entry = $this->createEntry($voucher, $period, $date, $userId, $description);
            foreach ($original->lines as $line) {
                $this->addLine($entry, $line->account_id, $line->side === 'debit' ? 'credit' : 'debit', (float) $line->amount, $description);
            }

            $entry = $this->finalize($entry);
            $this->recordCashMovements($entry, $reversalSource, $sourceId, $userId, $this->sourceBranch($sourceType, $sourceId, $userId));
            return $entry;
        });
    }

    public function postGroupedSalesReturn(int $returnId): JournalEntry
    {
        return DB::transaction(function () use ($returnId) {
            $return = DB::table('sales_returns')->where('id', $returnId)->lockForUpdate()->first();
            if (! $return) throw new RuntimeException('Sales return document not found.');
            $lines = DB::table('invoice_returns as r')
                ->join('prod_sales as s', 's.id', '=', 'r.original_sale_line_id')
                ->where('r.sales_return_id', $returnId)
                ->get(['r.quantity', 'r.original_returnable_unit_amount', 'r.customer_credit_unit_amount', 'r.return_to_inventory', 's.cost_price']);
            if ($lines->isEmpty()) throw new RuntimeException('Sales return document has no lines.');
            $revenue = round((float) $lines->sum(fn ($line) => (float) $line->quantity * (float) $line->original_returnable_unit_amount), 2);
            $credit = round((float) $lines->sum(fn ($line) => (float) $line->quantity * (float) $line->customer_credit_unit_amount), 2);
            $deduction = round($revenue - $credit, 2);
            $restockedCost = round((float) $lines->where('return_to_inventory', true)->sum(fn ($line) => (float) $line->quantity * (float) $line->cost_price), 2);
            $nonRestockedCost = round((float) $lines->where('return_to_inventory', false)->sum(fn ($line) => (float) $line->quantity * (float) $line->cost_price), 2);
            $cost = round($restockedCost + $nonRestockedCost, 2);
            $this->assertAmount($revenue); $this->assertAmount($cost);
            $postings = [['code' => '4100', 'side' => 'debit', 'amount' => $revenue, 'description' => 'Sales revenue reversal']];
            if ($restockedCost > 0) $postings[] = ['code' => '1400', 'side' => 'debit', 'amount' => $restockedCost, 'description' => 'Inventory restoration'];
            if ($nonRestockedCost > 0) $postings[] = ['code' => '5300', 'side' => 'debit', 'amount' => $nonRestockedCost, 'description' => 'Non-restock sales return cost'];
            $postings[] = ['code' => '5100', 'side' => 'credit', 'amount' => $cost, 'description' => 'COGS reversal'];
            if ($credit > 0) $postings[] = ['code' => '1300', 'side' => 'credit', 'amount' => $credit, 'description' => 'Customer return credit'];
            if ($deduction > 0) $postings[] = ['code' => '4200', 'side' => 'credit', 'amount' => $deduction, 'description' => 'Sales return deduction / fee'];
            return $this->postLines('sales_return', $returnId, $return->return_date, $return->created_by, 'Sales return', $postings);
        });
    }

    private function postEvent(string $type, string $table, int $eventId, string $description, callable $amountResolver, int $debitAccountId, int $creditAccountId, string $debitSide, string $creditSide): JournalEntry
    {
        return DB::transaction(function () use ($type, $table, $eventId, $description, $amountResolver, $debitAccountId, $creditAccountId, $debitSide, $creditSide) {
            $event = DB::table($table)->where('id', $eventId)->lockForUpdate()->first();
            if (!$event) {
                throw new RuntimeException('Accounting source event not found.');
            }

            $amount = round($amountResolver($event), 2);
            $this->assertAmount($amount);

            $existing = $this->existingEntry($type, $eventId);
            if ($existing) {
                return $existing;
            }

            $period = $this->periodFor($event->date);
            $voucher = $this->createVoucher($type, $eventId, $event->user_id, $period, $description);
            $entry = $this->createEntry($voucher, $period, $event->date, $event->user_id, $description);
            $this->addLine($entry, $debitAccountId, $debitSide, $amount, $description);
            $this->addLine($entry, $creditAccountId, $creditSide, $amount, $description);

            return $this->finalize($entry);
        });
    }

    private function postLines(string $type, int $sourceId, string $date, int $userId, string $description, array $lines): JournalEntry
    {
        $existing = $this->existingEntry($type, $sourceId);
        if ($existing) {
            return $existing;
        }

        $period = $this->periodFor($date);
        $voucher = $this->createVoucher($type, $sourceId, $userId, $period, $description);
        $entry = $this->createEntry($voucher, $period, $date, $userId, $description);

        foreach ($lines as $line) {
            $this->addLine($entry, isset($line['account_id'])?(int)$line['account_id']:$this->accountId($line['code']), $line['side'], $line['amount'], $line['description']);
        }

        $entry = $this->finalize($entry);
        $this->recordCashMovements($entry, $type, $sourceId, $userId, $this->sourceBranch($type, $sourceId, $userId));
        return $entry;
    }

    private function periodFor(string $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->where('status', 'open')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->lockForUpdate()
            ->first();

        if (!$period) {
            throw AccountingPeriodException::noOpenPeriod();
        }

        return $period;
    }

    private function createVoucher(string $type, int $eventId, int $userId, AccountingPeriod $period, string $description, ?string $voucherType = null): Voucher
    {
        return Voucher::query()->create([
            'voucher_type' => $voucherType ?? $this->voucherType($type),
            'source_type' => $type,
            'source_id' => $eventId,
            'voucher_number' => strtoupper($type) . '-' . $eventId . '-' . uniqid(),
            'accounting_period_id' => $period->id,
            'user_id' => $userId,
            'posted_at' => now(),
            'status' => 'posted',
            'notes' => $description,
        ]);
    }

    private function createEntry(Voucher $voucher, AccountingPeriod $period, string $date, int $userId, string $description): JournalEntry
    {
        return JournalEntry::query()->create([
            'voucher_id' => $voucher->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-' . $voucher->id . '-' . uniqid(),
            'entry_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'created_by' => $userId,
            'posted_at' => now(),
        ]);
    }

    private function addLine(JournalEntry $entry, int $accountId, string $side, float $amount, string $description): void
    {
        if (!DB::table('accounts')->where('id', $accountId)->exists()) {
            throw new RuntimeException('Accounting account not found.');
        }

        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $accountId,
            'side' => $side,
            'amount' => $amount,
            'description' => $description,
        ]);
    }

    private function accountId(string $code): int
    {
        $accountId = (int) DB::table('accounts')->where('code', $code)->where('active', true)->value('id');
        if ($accountId === 0) {
            throw new RuntimeException("Active accounting account {$code} not found.");
        }

        return $accountId;
    }

    private function finalize(JournalEntry $entry): JournalEntry
    {
        $entry->refreshTotals();
        if (!$entry->isBalanced()) {
            throw new RuntimeException('Journal entry is not balanced.');
        }

        return $entry->load('lines');
    }

    private function existingEntry(string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::query()
            ->whereHas('voucher', fn ($query) => $query->where('source_type', $sourceType)->where('source_id', $sourceId))
            ->first();
    }

    private function assertAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Accounting amount must be greater than zero.');
        }
    }

    private function voucherType(string $type): string
    {
        return match ($type) {
            'purchase_invoice' => 'purchase_invoice',
            'sales_invoice' => 'sales_invoice',
            'receipt' => 'receipt',
            'payment' => 'payment',
            'purchase_return' => 'purchase_return',
            'sales_return' => 'sales_return',
            'expense' => 'expense',
            'journal_adjustment' => 'journal_adjustment',
            'cheque_clear', 'cheque_bounce', 'other_income' => 'journal_adjustment',
            default => throw new InvalidArgumentException('Unsupported accounting voucher type.'),
        };
    }

    private function sourceBranch(string $type, int $id, int $userId): ?int
    {
        $map = ['expense'=>['expenses','branch_id'], 'other_income'=>['other_incomes','branch_id']];
        if (isset($map[$type])) return DB::table($map[$type][0])->where('id',$id)->value($map[$type][1]);
        return DB::table('users')->where('id',$userId)->value('branch_id');
    }

    private function recordCashMovements(JournalEntry $entry, string $type, int $sourceId, int $userId, $branchId): void
    {
        $cash = (int) DB::table('accounts')->where('code','1100')->value('id');
        foreach ($entry->lines->where('account_id',$cash) as $line) {
            DB::table('cash_movements')->updateOrInsert(['journal_entry_line_id'=>$line->id],[
                'branch_id'=>$branchId, 'responsible_user_id'=>$userId, 'source_type'=>$type,
                'source_id'=>$sourceId, 'direction'=>$line->side === 'debit' ? 'in' : 'out',
                'amount'=>$line->amount, 'occurred_at'=>$entry->posted_at ?? now(), 'created_at'=>now(), 'updated_at'=>now(),
            ]);
        }
    }
}
