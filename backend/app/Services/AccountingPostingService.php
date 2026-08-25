<?php

namespace App\Services;

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
            fn ($event) => (float) $event->amount,
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
            $isCashSale = ($invoice->payment_method ?? null) === 'cash' && (float) ($invoice->paid_amount ?? 0) > 0;
            $debitAccount = $isCashSale ? '1100' : '1300';
            $this->addLine($entry, $this->accountId($debitAccount), 'debit', $salesAmount, 'Receivable or cash');
            $this->addLine($entry, $this->accountId('4100'), 'credit', $salesAmount, 'Sales revenue');
            $this->addLine($entry, $this->accountId('5100'), 'debit', $costAmount, 'Cost of goods sold');
            $this->addLine($entry, $this->accountId('1400'), 'credit', $costAmount, 'Inventory cost');

            return $this->finalize($entry);
        });
    }

    public function postReceiptVoucher(int $receiptId): JournalEntry
    {
        return $this->postEvent(
            'receipt',
            'receipt_vouchers',
            $receiptId,
            'Customer receipt',
            fn ($event) => (float) $event->amount,
            $this->accountId('1100'),
            $this->accountId('1300'),
            'debit',
            'credit'
        );
    }

    public function postPaymentVoucher(int $paymentId): JournalEntry
    {
        return $this->postEvent(
            'payment',
            'payment_vouchers',
            $paymentId,
            'Supplier payment',
            fn ($event) => (float) $event->amount,
            $this->accountId('2100'),
            $this->accountId('1100'),
            'debit',
            'credit'
        );
    }

    public function postPurchaseReturn(int $purchaseInvoiceId): JournalEntry
    {
        return DB::transaction(function () use ($purchaseInvoiceId) {
            $invoice = DB::table('purchase_invoices')->where('id', $purchaseInvoiceId)->lockForUpdate()->first();
            $returns = DB::table('purch_returns')->where('invoice_id', $purchaseInvoiceId)->lockForUpdate()->get();
            if (!$invoice || $returns->isEmpty()) {
                throw new RuntimeException('Purchase return event not found.');
            }

            $amount = round((float) $returns->sum('line_total_return'), 2);
            $this->assertAmount($amount);

            return $this->postLines('purchase_return', $purchaseInvoiceId, $invoice->date, $invoice->user_id, 'Purchase return', [
                ['code' => '2100', 'side' => 'debit', 'amount' => $amount, 'description' => 'Accounts payable reduction'],
                ['code' => '1400', 'side' => 'credit', 'amount' => $amount, 'description' => 'Inventory decrease'],
            ]);
        });
    }

    public function postSalesReturn(int $salesInvoiceId): JournalEntry
    {
        return DB::transaction(function () use ($salesInvoiceId) {
            $invoice = DB::table('sales_invoices')->where('id', $salesInvoiceId)->lockForUpdate()->first();
            $returns = DB::table('invoice_returns')->where('invoice_id', $salesInvoiceId)->lockForUpdate()->get();
            if (!$invoice || $returns->isEmpty()) {
                throw new RuntimeException('Sales return event not found.');
            }

            $revenueAmount = round((float) $returns->sum(fn ($return) => $return->quantity * $return->price), 2);
            $costAmount = round((float) $returns->sum(function ($return) {
                return (float) (DB::table('prod_sales')
                    ->where('sales_invoice_id', $return->invoice_id)
                    ->where('batch_id', $return->batch_id)
                    ->value('cost_price') ?? 0) * $return->quantity;
            }), 2);
            $this->assertAmount($revenueAmount);
            $this->assertAmount($costAmount);
            $isCashSale = ($invoice->payment_method ?? null) === 'cash' && (float) ($invoice->paid_amount ?? 0) > 0;
            $receivableOrCash = $isCashSale ? '1100' : '1300';

            return $this->postLines('sales_return', $salesInvoiceId, $invoice->date, $invoice->user_id, 'Sales return', [
                ['code' => '4100', 'side' => 'debit', 'amount' => $revenueAmount, 'description' => 'Sales revenue reversal'],
                ['code' => $receivableOrCash, 'side' => 'credit', 'amount' => $revenueAmount, 'description' => 'Customer balance reversal'],
                ['code' => '1400', 'side' => 'debit', 'amount' => $costAmount, 'description' => 'Inventory restoration'],
                ['code' => '5100', 'side' => 'credit', 'amount' => $costAmount, 'description' => 'COGS reversal'],
            ]);
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

            return $this->postLines('expense', $expenseId, $expense->date ?: $expense->created_at, $expense->user_id, 'Operating expense', [
                ['code' => '5200', 'side' => 'debit', 'amount' => $amount, 'description' => 'Operating expense'],
                ['code' => $cashOrBank, 'side' => 'credit', 'amount' => $amount, 'description' => 'Cash or bank reduction'],
            ]);
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
            $this->addLine($entry, $this->accountId($line['code']), $line['side'], $line['amount'], $line['description']);
        }

        return $this->finalize($entry);
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
            throw new RuntimeException('No open accounting period exists for the event date.');
        }

        return $period;
    }

    private function createVoucher(string $type, int $eventId, int $userId, AccountingPeriod $period, string $description): Voucher
    {
        return Voucher::query()->create([
            'voucher_type' => $this->voucherType($type),
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
            default => throw new InvalidArgumentException('Unsupported accounting voucher type.'),
        };
    }
}
