<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingReportService
{
    public function generalLedger(?string $from = null, ?string $to = null, ?string $accountCode = null): Collection
    {
        return $this->postedLines($from, $to, $accountCode)
            ->map(function ($line) {
                $line->signed_amount = $line->side === 'debit' ? (float) $line->amount : -(float) $line->amount;
                return $line;
            });
    }

    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $rows = $this->accountTotals($from, $to);
        $totalDebit = round($rows->sum('debit'), 2);
        $totalCredit = round($rows->sum('credit'), 2);

        return [
            'accounts' => $rows->values(),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => $totalDebit === $totalCredit,
        ];
    }

    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $rows = $this->accountTotals($from, $to)->filter(fn ($row) => in_array($row->account_type, ['revenue', 'expense'], true))->values();
        $revenue = round($rows->where('account_type', 'revenue')->sum('credit') - $rows->where('account_type', 'revenue')->sum('debit'), 2);
        $expenses = round($rows->where('account_type', 'expense')->sum('debit') - $rows->where('account_type', 'expense')->sum('credit'), 2);

        return [
            'accounts' => $rows,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_income' => round($revenue - $expenses, 2),
        ];
    }

    public function balanceSheet(?string $to = null): array
    {
        $rows = $this->accountTotals(null, $to)->filter(fn ($row) => in_array($row->account_type, ['asset', 'liability', 'equity', 'contra_asset', 'contra_revenue'], true))->values();
        $assets = round($rows->whereIn('account_type', ['asset', 'contra_asset'])->sum('balance'), 2);
        $liabilities = round($rows->where('account_type', 'liability')->sum('balance'), 2);
        $equity = round($rows->where('account_type', 'equity')->sum('balance'), 2);

        return [
            'accounts' => $rows,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'liabilities_and_equity' => round($liabilities + $equity, 2),
        ];
    }

    public function customerStatement(int $customerId, ?string $from = null, ?string $to = null): array
    {
        return $this->partyStatement($customerId, 'customer', '1300', $from, $to);
    }

    public function supplierStatement(int $supplierId, ?string $from = null, ?string $to = null): array
    {
        return $this->partyStatement($supplierId, 'supplier', '2100', $from, $to);
    }

    public function customerStatementIdsWithBalances(): array
    {
        return DB::table('customers')->get(['id', 'name'])->map(fn ($party) => ['name' => $party->name, 'balance' => $this->customerStatement($party->id)['balance']])->filter(fn ($party) => $party['balance'] > 0)->values()->all();
    }

    public function supplierStatementIdsWithBalances(): array
    {
        return DB::table('providers')->get(['id', 'name'])->map(fn ($party) => ['name' => $party->name, 'balance' => $this->supplierStatement($party->id)['balance']])->filter(fn ($party) => $party['balance'] > 0)->values()->all();
    }

    public function inventoryValuation(?string $to = null): array
    {
        $row = $this->accountTotals(null, $to)->firstWhere('code', '1400');
        $value = $row ? (float) $row->balance : 0.0;

        return [
            'account_code' => '1400',
            'account_name' => 'Inventory Asset',
            'value' => round($value, 2),
            'debit' => $row ? (float) $row->debit : 0.0,
            'credit' => $row ? (float) $row->credit : 0.0,
        ];
    }

    private function accountTotals(?string $from, ?string $to): Collection
    {
        return $this->postedLines($from, $to)
            ->groupBy('account_id')
            ->map(function (Collection $lines) {
                $first = $lines->first();
                $debit = round($lines->where('side', 'debit')->sum('amount'), 2);
                $credit = round($lines->where('side', 'credit')->sum('amount'), 2);
                $balance = in_array($first->account_type, ['asset', 'expense'], true)
                    ? $debit - $credit
                    : $credit - $debit;

                $first->debit = $debit;
                $first->credit = $credit;
                $first->balance = round($balance, 2);
                return $first;
            });
    }

    private function partyStatement(int $partyId, string $partyType, string $accountCode, ?string $from, ?string $to): array
    {
        $lines = $this->postedLines($from, $to, $accountCode)->filter(function ($line) use ($partyId, $partyType) {
            return $this->sourceBelongsToParty($line->source_type, (int) $line->source_id, $partyId, $partyType);
        })->values();

        $debits = round($lines->where('side', 'debit')->sum('amount'), 2);
        $credits = round($lines->where('side', 'credit')->sum('amount'), 2);
        $balance = $partyType === 'customer' ? $debits - $credits : $credits - $debits;

        return [
            'party_id' => $partyId,
            'party_type' => $partyType,
            'lines' => $lines,
            'total_debit' => $debits,
            'total_credit' => $credits,
            'balance' => round($balance, 2),
        ];
    }

    private function sourceBelongsToParty(string $sourceType, int $sourceId, int $partyId, string $partyType): bool
    {
        $sources = $partyType === 'customer'
            ? ['sales_invoice' => 'sales_invoices', 'sales_return' => 'sales_invoices', 'receipt' => 'receipt_vouchers']
            : ['purchase_invoice' => 'purchase_invoices', 'purchase_return' => 'purchase_invoices', 'payment' => 'payment_vouchers'];
        $column = $partyType === 'customer' ? 'customer_id' : 'provider_id';

        if (!isset($sources[$sourceType])) {
            return false;
        }

        return (int) DB::table($sources[$sourceType])->where('id', $sourceId)->value($column) === $partyId;
    }

    private function postedLines(?string $from, ?string $to, ?string $accountCode = null): Collection
    {
        $query = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.status', 'posted')
            ->where('vouchers.status', 'posted')
            ->select([
                'lines.id', 'lines.journal_entry_id', 'lines.account_id', 'lines.side', 'lines.amount',
                'lines.description', 'entries.entry_number', 'entries.entry_date', 'entries.description as entry_description',
                'vouchers.source_type', 'vouchers.source_id', 'accounts.code', 'accounts.name as account_name', 'accounts.account_type',
            ])
            ->orderBy('entries.entry_date')
            ->orderBy('lines.id');

        if ($from) {
            $query->whereDate('entries.entry_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('entries.entry_date', '<=', $to);
        }
        if ($accountCode) {
            $query->where('accounts.code', $accountCode);
        }

        return $query->get();
    }
}
