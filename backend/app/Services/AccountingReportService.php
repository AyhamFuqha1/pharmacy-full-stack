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
                $line->source_label = $line->business_reference ?: $this->legacySourceLabel($line->source_type);
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
        $balance = fn (string $code, string $type) => round((float) optional($rows->firstWhere('code', $code))->{$type === 'revenue' ? 'credit' : 'debit'} - (float) optional($rows->firstWhere('code', $code))->{$type === 'revenue' ? 'debit' : 'credit'}, 2);
        $netSales = $balance('4100', 'revenue'); $otherIncome = $balance('4200', 'revenue');
        $cogs = $balance('5100', 'expense'); $operatingExpenses = $balance('5200', 'expense'); $inventoryAdjustments = $balance('5300', 'expense');
        $grossProfit = round($netSales - $cogs, 2); $netIncome = round($netSales + $otherIncome - $cogs - $operatingExpenses - $inventoryAdjustments, 2);

        return [
            'accounts' => $rows,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_sales_revenue' => $netSales,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin_percent' => $netSales != 0 ? round($grossProfit / $netSales * 100, 2) : 0.0,
            'other_income' => $otherIncome,
            'operating_expenses' => $operatingExpenses,
            'inventory_adjustments' => $inventoryAdjustments,
            'net_income' => $netIncome,
            'gl_reconciliation' => ['expected_net_income' => round($revenue - $expenses, 2), 'difference' => round($netIncome - ($revenue - $expenses), 2), 'reconciled' => abs($netIncome - ($revenue - $expenses)) < 0.01],
            'branch_filter_supported' => false,
        ];
    }

    public function balanceSheet(?string $to = null): array
    {
        $rows = $this->accountTotals(null, $to)->filter(fn ($row) => in_array($row->account_type, ['asset', 'liability', 'equity', 'contra_asset', 'contra_revenue'], true))->values();
        $assets = round($rows->whereIn('account_type', ['asset', 'contra_asset'])->sum('balance'), 2);
        $liabilities = round($rows->where('account_type', 'liability')->sum('balance'), 2);
        $baseEquity = round($rows->where('account_type', 'equity')->sum('balance'), 2);
        $earnings = $this->incomeStatement(null, $to)['net_income'];
        $equity = round($baseEquity + $earnings, 2);
        $liabilitiesAndEquity = round($liabilities + $equity, 2);

        return [
            'accounts' => $rows,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'base_equity' => $baseEquity,
            'current_earnings' => $earnings,
            'equity' => $equity,
            'total_equity' => $equity,
            'liabilities_and_equity' => $liabilitiesAndEquity,
            'balanced' => abs($assets - $liabilitiesAndEquity) < 0.01,
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

    public function customerStatementReport(int $customerId, $request): array
    {
        return $this->paginatedPartyStatement($customerId, 'customer', '1300', $request);
    }

    public function supplierStatementReport(int $supplierId, $request): array
    {
        return $this->paginatedPartyStatement($supplierId, 'supplier', '2100', $request);
    }

    private function paginatedPartyStatement(int $partyId, string $partyType, string $accountCode, $request): array
    {
        $from = $request->input('from'); $to = $request->input('to');
        $perPage = min(max((int) $request->input('per_page', 50), 1), 100); $page = max((int) $request->input('page', 1), 1);
        $sign = $partyType === 'customer' ? "CASE WHEN lines.side = 'debit' THEN lines.amount ELSE -lines.amount END" : "CASE WHEN lines.side = 'credit' THEN lines.amount ELSE -lines.amount END";
        $openingQuery = $this->partyLinesQuery($partyId, $partyType, $accountCode);
        if ($from) $openingQuery->whereDate('entries.entry_date', '<', $from); else $openingQuery->whereRaw('1 = 0');
        $opening = round((float) $openingQuery->sum(DB::raw($sign)), 2);
        $period = $this->partyLinesQuery($partyId, $partyType, $accountCode)
            ->when($from, fn ($q) => $q->whereDate('entries.entry_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('entries.entry_date', '<=', $to))
            ->addSelect(DB::raw("$sign as signed_amount"))->addSelect(DB::raw("SUM($sign) OVER (ORDER BY entries.entry_date ASC, entries.id ASC, lines.id ASC) as period_running"));
        $closingMovement = round((float) DB::query()->fromSub(clone $period, 'period')->sum('signed_amount'), 2);
        $visible = DB::query()->fromSub($period, 'statement')
            ->when($request->filled('transaction_type'), fn ($q) => $q->where('source_type', $request->transaction_type))
            ->when($request->filled('reference'), fn ($q) => $q->where(fn ($x) => $x->where('business_reference', 'like', '%'.$request->reference.'%')->orWhere('voucher_number', 'like', '%'.$request->reference.'%')->orWhere('reference_number', 'like', '%'.$request->reference.'%')->orWhere('entry_number', 'like', '%'.$request->reference.'%')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x->where('description', 'like', '%'.$request->search.'%')->orWhere('entry_description', 'like', '%'.$request->search.'%')->orWhere('source_type', 'like', '%'.$request->search.'%')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('created_by', $request->integer('user_id')));
        $totals = (clone $visible)->selectRaw("SUM(CASE WHEN side = 'debit' THEN amount ELSE 0 END) total_debit, SUM(CASE WHEN side = 'credit' THEN amount ELSE 0 END) total_credit")->first();
        $ordered = $visible->orderBy('entry_date')->orderBy('journal_entry_id')->orderBy('id');
        $paginator = $request->boolean('all') ? null : $ordered->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($paginator ? $paginator->items() : $ordered->get())->map(function ($line) use ($opening) {
            $line->debit = $line->side === 'debit' ? (float) $line->amount : 0.0; $line->credit = $line->side === 'credit' ? (float) $line->amount : 0.0;
            $line->running_balance = round($opening + (float) $line->period_running, 2); $line->source_label = $line->business_reference ?: $this->legacySourceLabel($line->source_type);
            return $line;
        });
        $meta = $paginator ? ['current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),'per_page'=>$paginator->perPage(),'total'=>$paginator->total(),'from'=>$paginator->firstItem(),'to'=>$paginator->lastItem()] : ['current_page'=>1,'last_page'=>1,'per_page'=>$rows->count(),'total'=>$rows->count(),'from'=>$rows->isEmpty()?null:1,'to'=>$rows->isEmpty()?null:$rows->count()];
        return ['party_id'=>$partyId,'party_type'=>$partyType,'opening_balance'=>$opening,'lines'=>$rows,'total_debit'=>round((float)($totals->total_debit??0),2),'total_credit'=>round((float)($totals->total_credit??0),2),'period_change'=>$closingMovement,'closing_balance'=>round($opening+$closingMovement,2),'balance'=>round($opening+$closingMovement,2),'meta'=>$meta,'balance_semantics'=>'true_account_balance'];
    }

    private function partyLinesQuery(int $partyId, string $partyType, string $accountCode)
    {
        return $this->postedLinesQuery(null, null, $accountCode)->where(function ($scope) use ($partyId, $partyType) {
            $direct = $partyType === 'customer' ? ['sales_invoice'=>['sales_invoices','customer_id'],'sales_invoice_reversal'=>['sales_invoices','customer_id'],'receipt'=>['receipt_vouchers','customer_id']] : ['purchase_invoice'=>['purchase_invoices','provider_id'],'payment'=>['payment_vouchers','provider_id']];
            foreach ($direct as $type => [$table,$column]) $scope->orWhere(fn ($q) => $q->where('vouchers.source_type',$type)->whereExists(fn ($sub) => $sub->selectRaw('1')->from($table)->whereColumn("$table.id",'vouchers.source_id')->where("$table.$column",$partyId)));
            if ($partyType === 'customer') {
                $scope->orWhere(fn ($q) => $q->where('vouchers.source_type','sales_return')->whereExists(fn ($sub) => $sub->selectRaw('1')->from('invoice_returns')->join('sales_invoices','sales_invoices.id','=','invoice_returns.invoice_id')->whereColumn('invoice_returns.id','vouchers.source_id')->where('sales_invoices.customer_id',$partyId)));
                $scope->orWhere(fn ($q) => $q->where('vouchers.source_type','sales_return')->whereExists(fn ($sub) => $sub->selectRaw('1')->from('sales_returns')->whereColumn('sales_returns.id','vouchers.source_id')->where('sales_returns.customer_id',$partyId)));
            }
            else $scope->orWhere(fn ($q) => $q->where('vouchers.source_type','purchase_return')->whereExists(fn ($sub) => $sub->selectRaw('1')->from('purch_returns')->join('purchase_invoices','purchase_invoices.id','=','purch_returns.invoice_id')->whereColumn('purch_returns.id','vouchers.source_id')->where('purchase_invoices.provider_id',$partyId)));
            $column=$partyType==='customer'?'customer_id':'provider_id'; $direction=$partyType==='customer'?'in':'out';
            $scope->orWhere(fn ($q) => $q->whereIn('vouchers.source_type',['cheque_clear','cheque_bounce'])->whereExists(fn ($sub) => $sub->selectRaw('1')->from('checks')->whereColumn('checks.id','vouchers.source_id')->where('checks.direction',$direction)->where("checks.$column",$partyId)));
        });
    }

    public function customerStatementIdsWithBalances(): array
    {
        return DB::table('customers')->get(['id', 'name'])->map(fn ($party) => ['id' => $party->id, 'name' => $party->name, 'balance' => $this->customerStatement($party->id)['balance']])->filter(fn ($party) => $party['balance'] > 0)->values()->all();
    }

    public function supplierStatementIdsWithBalances(): array
    {
        return DB::table('providers')->get(['id', 'name'])->map(fn ($party) => ['id' => $party->id, 'name' => $party->name, 'balance' => $this->supplierStatement($party->id)['balance']])->filter(fn ($party) => $party['balance'] > 0)->values()->all();
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
        $openingLines = $from ? $this->postedLines(null, date('Y-m-d', strtotime($from.' -1 day')), $accountCode)->filter(function ($line) use ($partyId, $partyType) {
            return $this->sourceBelongsToParty($line->source_type, (int) $line->source_id, $partyId, $partyType);
        }) : collect();
        $openingDebits = (float) $openingLines->where('side', 'debit')->sum('amount');
        $openingCredits = (float) $openingLines->where('side', 'credit')->sum('amount');
        $openingBalance = $partyType === 'customer' ? $openingDebits - $openingCredits : $openingCredits - $openingDebits;

        $running = $openingBalance;
        $lines = $this->postedLines($from, $to, $accountCode)->filter(function ($line) use ($partyId, $partyType) {
            return $this->sourceBelongsToParty($line->source_type, (int) $line->source_id, $partyId, $partyType);
        })->values()->each(function ($line) use (&$running, $partyType) {
            $line->source_label = $line->business_reference ?: $this->legacySourceLabel($line->source_type);
            $running += $partyType === 'customer'
                ? ($line->side === 'debit' ? (float) $line->amount : -(float) $line->amount)
                : ($line->side === 'credit' ? (float) $line->amount : -(float) $line->amount);
            $line->debit = $line->side === 'debit' ? (float) $line->amount : 0.0;
            $line->credit = $line->side === 'credit' ? (float) $line->amount : 0.0;
            $line->running_balance = round($running, 2);
            $line->payment_method = $this->sourcePaymentMethod($line->source_type, (int) $line->source_id);
            $line->cheque_id = $this->sourceChequeId($line->source_type, (int) $line->source_id);
        });

        $debits = round($lines->where('side', 'debit')->sum('amount'), 2);
        $credits = round($lines->where('side', 'credit')->sum('amount'), 2);
        $balance = $partyType === 'customer' ? $debits - $credits : $credits - $debits;

        return [
            'party_id' => $partyId,
            'party_type' => $partyType,
            'opening_balance' => round($openingBalance, 2),
            'lines' => $lines,
            'total_debit' => $debits,
            'total_credit' => $credits,
            'period_change' => round($balance, 2),
            'closing_balance' => round($openingBalance + $balance, 2),
            'balance' => round($openingBalance + $balance, 2),
        ];
    }

    public function paginatedGeneralLedger(?string $from, ?string $to, ?string $accountCode, int $page = 1, int $perPage = 50): array
    {
        $filtered = $this->postedLinesQuery($from, $to, $accountCode);
        $totalDebit = round((float) (clone $filtered)->where('lines.side', 'debit')->sum('lines.amount'), 2);
        $totalCredit = round((float) (clone $filtered)->where('lines.side', 'credit')->sum('lines.amount'), 2);
        $paginator = $filtered
            ->orderByDesc('entries.entry_date')
            ->orderByDesc('entries.id')
            ->orderByDesc('lines.id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(function ($line) {
                $line->signed_amount = $line->side === 'debit' ? (float) $line->amount : -(float) $line->amount;
                $line->source_label = $line->business_reference ?: $this->legacySourceLabel($line->source_type);
                return $line;
            });

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'totals' => ['total_debit' => $totalDebit, 'total_credit' => $totalCredit],
        ];
    }

    private function sourcePaymentMethod(string $type, int $id): ?string
    {
        return match ($type) {
            'receipt' => DB::table('receipt_vouchers')->where('id', $id)->value('payment_method'),
            'payment' => DB::table('payment_vouchers')->where('id', $id)->value('payment_method'),
            'sales_invoice', 'sales_invoice_reversal' => DB::table('sales_invoices')->where('id', $id)->value('payment_method'),
            default => null,
        };
    }

    private function sourceChequeId(string $type, int $id): ?int
    {
        $value = match ($type) {
            'receipt' => DB::table('checks')->where('receipt_voucher_id', $id)->value('id'),
            'payment' => DB::table('checks')->where('payment_voucher_id', $id)->value('id'),
            'cheque_clear', 'cheque_bounce' => $id,
            default => null,
        };
        return $value ? (int) $value : null;
    }

    private function sourceBelongsToParty(string $sourceType, int $sourceId, int $partyId, string $partyType): bool
    {
        if (in_array($sourceType, ['cheque_clear', 'cheque_bounce'], true)) {
            $partyColumn = $partyType === 'customer' ? 'customer_id' : 'provider_id';
            $direction = $partyType === 'customer' ? 'in' : 'out';

            return DB::table('checks')
                ->where('id', $sourceId)
                ->where('direction', $direction)
                ->where($partyColumn, $partyId)
                ->exists();
        }

        if ($partyType === 'customer' && $sourceType === 'sales_return') {
            return DB::table('sales_returns')->where('id',$sourceId)->where('customer_id',$partyId)->exists()
                || DB::table('invoice_returns as returns')
                ->join('sales_invoices as invoices', 'invoices.id', '=', 'returns.invoice_id')
                ->where('returns.id', $sourceId)
                ->where('invoices.customer_id', $partyId)
                ->exists();
        }

        if ($partyType === 'supplier' && $sourceType === 'purchase_return') {
            return DB::table('purch_returns as returns')
                ->join('purchase_invoices as invoices', 'invoices.id', '=', 'returns.invoice_id')
                ->where('returns.id', $sourceId)
                ->where('invoices.provider_id', $partyId)
                ->exists();
        }

        $sources = $partyType === 'customer'
            ? [
                'sales_invoice' => 'sales_invoices',
                'sales_invoice_reversal' => 'sales_invoices',
                'receipt' => 'receipt_vouchers',
            ]
            : ['purchase_invoice' => 'purchase_invoices', 'payment' => 'payment_vouchers'];
        $column = $partyType === 'customer' ? 'customer_id' : 'provider_id';

        if (!isset($sources[$sourceType])) {
            return false;
        }

        return (int) DB::table($sources[$sourceType])->where('id', $sourceId)->value($column) === $partyId;
    }

    private function postedLines(?string $from, ?string $to, ?string $accountCode = null): Collection
    {
        return $this->postedLinesQuery($from, $to, $accountCode)
            ->orderBy('entries.entry_date')
            ->orderBy('entries.id')
            ->orderBy('lines.id')
            ->get();
    }

    private function postedLinesQuery(?string $from, ?string $to, ?string $accountCode = null)
    {
        $query = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('vouchers', 'vouchers.id', '=', 'entries.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->leftJoin('users as entry_users', 'entry_users.id', '=', 'entries.created_by')
            ->where('entries.status', 'posted')
            ->where('vouchers.status', 'posted')
            ->select([
                'lines.id', 'lines.journal_entry_id', 'lines.account_id', 'lines.side', 'lines.amount',
                'lines.description', 'entries.entry_number', 'entries.entry_date', 'entries.description as entry_description',
                'entries.created_by', 'entry_users.name as created_by_name', 'vouchers.source_type', 'vouchers.source_id', 'vouchers.voucher_number', 'vouchers.reference_number', 'accounts.code', 'accounts.name as account_name', 'accounts.account_type',
            ])
            ->selectRaw("CASE
                WHEN vouchers.source_type IN ('sales_invoice','sales_invoice_reversal') THEN (SELECT document_number FROM sales_invoices WHERE sales_invoices.id = vouchers.source_id)
                WHEN vouchers.source_type = 'purchase_invoice' THEN (SELECT document_number FROM purchase_invoices WHERE purchase_invoices.id = vouchers.source_id)
                WHEN vouchers.source_type = 'sales_return' THEN COALESCE((SELECT document_number FROM sales_returns WHERE sales_returns.id = vouchers.source_id), (SELECT document_number FROM invoice_returns WHERE invoice_returns.id = vouchers.source_id))
                WHEN vouchers.source_type = 'purchase_return' THEN (SELECT document_number FROM purch_returns WHERE purch_returns.id = vouchers.source_id)
                WHEN vouchers.source_type = 'receipt' THEN (SELECT document_number FROM receipt_vouchers WHERE receipt_vouchers.id = vouchers.source_id)
                WHEN vouchers.source_type = 'payment' THEN (SELECT document_number FROM payment_vouchers WHERE payment_vouchers.id = vouchers.source_id)
                WHEN vouchers.source_type = 'expense' THEN (SELECT document_number FROM expenses WHERE expenses.id = vouchers.source_id)
                WHEN vouchers.source_type = 'other_income' THEN (SELECT document_number FROM other_incomes WHERE other_incomes.id = vouchers.source_id)
                WHEN vouchers.source_type IN ('cheque_clear','cheque_bounce') THEN (SELECT check_number FROM checks WHERE checks.id = vouchers.source_id)
                ELSE NULL END as business_reference");

        if ($from) {
            $query->whereDate('entries.entry_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('entries.entry_date', '<=', $to);
        }
        if ($accountCode) {
            $query->where('accounts.code', $accountCode);
        }

        return $query;
    }

    private function legacySourceLabel(string $type): string
    {
        return 'Legacy '.str($type)->replace('_', ' ')->lower().' (no document number)';
    }
}
