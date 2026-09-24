<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrintDocumentService
{
    private const DOCS = [
        'sale' => ['sales_invoices', 'prod_sales', 'sales_invoice_id', 'Sales Invoice'], 'sales_invoice' => ['sales_invoices', 'prod_sales', 'sales_invoice_id', 'Sales Invoice'],
        'purchase' => ['purchase_invoices', 'prod_purch', 'purchase_invoice_id', 'Purchase Invoice'], 'purchase_invoice' => ['purchase_invoices', 'prod_purch', 'purchase_invoice_id', 'Purchase Invoice'],
        'sales_return' => ['invoice_returns', null, null, 'Sales Return'], 'purchase_return' => ['purch_returns', null, null, 'Purchase Return'],
        'receipt' => ['receipt_vouchers', null, null, 'Customer Receipt'], 'customer_receipt' => ['receipt_vouchers', null, null, 'Customer Receipt'],
        'payment' => ['payment_vouchers', null, null, 'Supplier Payment'], 'supplier_payment' => ['payment_vouchers', null, null, 'Supplier Payment'],
        'cheque' => ['checks', null, null, 'Cheque'], 'stock_count' => ['stock_counts', 'stock_count_lines', 'stock_count_id', 'Stock Count'],
        'stock_adjustment' => ['stock_adjustments', null, null, 'Stock Adjustment'], 'stock_transfer' => ['stock_transfers', 'stock_transfer_lines', 'transfer_id', 'Stock Transfer'],
        'opening_balance' => ['opening_balance_sessions', 'opening_balance_lines', 'session_id', 'Opening Balance'], 'expense' => ['expenses', null, null, 'Expense'], 'other_income' => ['other_incomes', null, null, 'Other Income'], 'cashier_shift' => ['cashier_shifts', null, null, 'Cashier Shift Close'],
    ];

    public function document(Request $r, string $type, int $id): array
    {
        $this->authorizeOperationalPrint($r, $type, false);
        if ($type === 'sales_return' && ($header = DB::table('sales_returns')->find($id))) {
            $meta = $this->meta($r, 'Sales Return', $header->document_number, $header->status, $header->branch_id, $header->return_date);
            $meta['print_identity'] = app(PrintIdentityService::class)->resolve('sales_return', $id);

            return $this->groupedSalesReturnDocument($header, $meta);
        }
        abort_unless(isset(self::DOCS[$type]), 404);
        [$table,$lineTable,$fk,$title] = self::DOCS[$type];
        $doc = DB::table($table)->find($id);
        abort_unless($doc, 404);
        $lines = $this->lines($type, $lineTable, $fk, $id, $doc);
        [$doc,$lines,$party,$branchId] = $this->decorateBusinessDocument($type, $doc, $lines);
        if (! $r->user()->hasPermission('reports.view_cost')) {
            [$doc, $lines] = $this->redactCostFields($doc, $lines);
        }
        $meta = $this->meta($r, $title, $doc->document_number ?? $doc->check_number ?? null, $doc->status ?? 'posted', $branchId, $doc->date ?? $doc->effective_date ?? $doc->posted_at ?? $doc->opened_at ?? $doc->created_at);
        $snapshotType = match ($type) {
            'sale' => 'sales_invoice', 'purchase' => 'purchase_invoice', 'receipt' => 'customer_receipt', 'payment' => 'supplier_payment', default => $type
        };
        $meta['print_identity'] = app(PrintIdentityService::class)->resolve($snapshotType, $id);
        if (in_array($type, ['sale', 'sales_invoice'], true)) {
            return $this->saleDocument($doc, $lines, $party, $meta, $id);
        }
        if ($type === 'sales_return') {
            return $this->salesReturnDocument($doc, $meta, $id);
        }

        return ['meta' => $meta, 'content' => ['entity' => $doc, 'party' => $party, 'filters_used' => [], 'opening_balance_stock' => $doc->opening_cash ?? null, 'rows_lines' => $lines, 'totals_summary' => $this->totals($type, $doc, $lines), 'closing_balance_stock' => $doc->expected_cash ?? null, 'source_references' => []]];
    }

    public function report(Request $r, string $type): array
    {
        $this->authorizeOperationalPrint($r, $type, true);
        $rules = ['entity_id' => 'nullable|integer', 'customer_id' => 'nullable|integer', 'provider_id' => 'nullable|integer', 'product_id' => 'nullable|integer', 'batch_id' => 'nullable|integer', 'recall_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'transaction_type' => 'nullable|string|max:50', 'reference' => 'nullable|string|max:100', 'search' => 'nullable|string|max:255', 'user_id' => 'nullable|integer'];
        if (in_array($type, ['product_statement', 'batch_statement'], true)) {
            $rules = array_merge($rules, ['branch_id' => 'nullable|exists:branches,id', 'movement_type' => 'nullable|string|max:50']);
        }
        $v = $r->validate($rules);
        $rows = collect();
        $summary = [];
        $title = str($type)->replace('_', ' ')->title()->toString();
        $opening = null;
        $closing = null;
        $branch = $r->integer('branch_id') ?: null;
        $party = null;
        $filtersUsed = $v;
        if ($type === 'customer_statement') {
            $id = (int) ($v['customer_id'] ?? $v['entity_id'] ?? 0);
            abort_if(! $id, 422, 'customer_id is required.');
            $statementRequest = Request::create('/', 'GET', array_merge($v, ['all' => true]));
            $s = app(AccountingReportService::class)->customerStatementReport($id, $statementRequest);
            $rows = collect($s['lines'])->map(fn ($line) => collect((array) $line)->only(['entry_date', 'source_type', 'source_label', 'description', 'debit', 'credit', 'running_balance'])->all());
            $summary = collect($s)->only(['opening_balance', 'total_debit', 'total_credit', 'closing_balance'])->all();
            $opening = $s['opening_balance'];
            $closing = $s['closing_balance'];
            $party = DB::table('customers')->where('id', $id)->first(['name', 'phone_num']);
            $filtersUsed = collect($v)->only(['from', 'to', 'transaction_type', 'reference', 'search', 'user_id'])->filter(fn ($value) => $value !== null && $value !== '')->all();
        } elseif ($type === 'supplier_statement') {
            $id = (int) ($v['provider_id'] ?? $v['entity_id'] ?? 0);
            abort_if(! $id, 422, 'provider_id is required.');
            $statementRequest = Request::create('/', 'GET', array_merge($v, ['all' => true]));
            $s = app(AccountingReportService::class)->supplierStatementReport($id, $statementRequest);
            $rows = collect($s['lines'])->map(fn ($line) => collect((array) $line)->only(['entry_date', 'source_type', 'source_label', 'description', 'debit', 'credit', 'running_balance'])->all());
            $summary = collect($s)->only(['opening_balance', 'total_debit', 'total_credit', 'closing_balance'])->all();
            $opening = $s['opening_balance'];
            $closing = $s['closing_balance'];
            $party = DB::table('providers')->where('id', $id)->first(['name', 'phone_num']);
            $filtersUsed = collect($v)->only(['from', 'to', 'transaction_type', 'reference', 'search', 'user_id'])->filter(fn ($value) => $value !== null && $value !== '')->all();
        } elseif (in_array($type, ['product_statement', 'batch_statement'])) {
            $product = (int) ($v['product_id'] ?? 0);
            if (! $product && ($v['batch_id'] ?? null)) {
                $product = (int) DB::table('product_batches')->where('id', $v['batch_id'])->value('product_id');
            }abort_if(! $product, 422, 'product_id or batch_id is required.');
            $statementRequest = Request::create('/', 'GET', collect($v)->only(['from', 'to', 'branch_id', 'batch_id', 'movement_type'])->filter(fn ($value) => $value !== null)->all());
            $statementRequest->setUserResolver(fn () => $r->user());
            $x = app(ProductStockStatementService::class)->query($statementRequest, $product, true);
            $rows = collect($x['movements']);
            $summary = ['running_balance_available' => $x['running_balance_available'], 'opening_quantity' => $x['opening_quantity'], 'closing_quantity' => $x['closing_quantity']];
            if (isset($x['running_balance_reason'])) {
                $summary['running_balance_reason'] = $x['running_balance_reason'];
            }$opening = $x['opening_quantity'];
            $closing = $x['closing_quantity'];
        } elseif (in_array($type, ['batch_recall_statement', 'recall_statement'])) {
            $id = (int) ($v['recall_id'] ?? $v['entity_id'] ?? 0);
            $x = app(ErpAnalyticsService::class)->recall($r, $id);
            $rows = collect($x['sales_documents'])->concat($x['source_purchases'])->concat($x['prescription_dispensing']);
            $summary = collect($x)->except(['sales_documents', 'source_purchases', 'prescription_dispensing'])->all();
            $closing = $x['current_quantity'];
        } elseif ($type === 'controlled_medicine_ledger') {
            $x = app(ErpAnalyticsService::class)->controlled($r, true);
            $rows = collect($x['rows']);
            $summary = $x['summary'];
            $opening = $summary['opening'];
            $closing = $summary['closing'];
        } else {
            abort(404, 'Unknown printable report type.');
        }
        $meta = $this->meta($r, $title, null, 'generated', $branch, $v['to'] ?? now()->toDateString(), $v['from'] ?? null, $v['to'] ?? null);
        $meta['print_identity'] = app(PrintIdentityService::class)->currentPrintable();

        return ['meta' => $meta, 'content' => ['entity' => null, 'party' => $party, 'filters_used' => $filtersUsed, 'opening_balance_stock' => $opening, 'rows_lines' => $rows, 'totals_summary' => $summary, 'closing_balance_stock' => $closing, 'source_references' => []]];
    }

    private function lines(string $type, $table, $fk, int $id, $doc)
    {
        if ($type === 'cashier_shift') {
            return DB::table('cash_movements')->where('responsible_user_id', $doc->cashier_id)->where('branch_id', $doc->branch_id)->whereBetween('occurred_at', [$doc->opened_at, $doc->closed_at ?? now()])->get();
        }if (! $table) {
            return in_array($type, ['sales_return', 'purchase_return'], true) ? collect([$doc]) : collect();
        }$q = DB::table($table.' as l')->where('l.'.$fk, $id);
        if ($table === 'prod_sales') {
            $q->leftJoin('product_batches as b', 'b.id', '=', 'l.batch_id')->leftJoin('products as p', 'p.id', '=', 'b.product_id')->leftJoin('units as u', 'u.id', '=', 'l.transaction_unit_id')->addSelect('l.*', 'p.name as product_name', 'b.batch_number', 'u.name as unit_name', 'u.symbol as unit_symbol');
        } elseif ($table === 'prod_purch') {
            $q->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('product_batches as b', 'b.id', '=', 'l.batch_id')->leftJoin('units as u', 'u.id', '=', 'l.transaction_unit_id')->addSelect('l.*', 'p.name as product_name', 'b.batch_number', 'u.name as unit_name', 'u.symbol as unit_symbol');
        } elseif ($table === 'stock_count_lines') {
            $q->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('product_batches as b', 'b.id', '=', 'l.batch_id')->addSelect('l.*', 'p.name as product_name', 'b.batch_number');
        } elseif ($table === 'stock_transfer_lines') {
            $q->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('units as u', 'u.id', '=', 'l.transaction_unit_id')->addSelect('l.*', 'p.name as product_name', 'u.name as unit_name', 'u.symbol as unit_symbol');
        } elseif ($table === 'opening_balance_lines') {
            $q->leftJoin('products as p', 'p.id', '=', 'l.product_id')->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')->leftJoin('providers as pr', 'pr.id', '=', 'l.provider_id')->leftJoin('units as u', 'u.id', '=', 'l.transaction_unit_id')->addSelect('l.*', 'p.name as product_name', 'c.name as customer_name', 'pr.name as supplier_name', 'u.name as unit_name', 'u.symbol as unit_symbol');
        } else {
            $q->select('l.*');
        }

        return $q->get();
    }

    private function decorateBusinessDocument(string $type, object $doc, $lines): array
    {
        $branchId = $doc->branch_id ?? $doc->source_branch_id ?? null;
        $party = $this->party($doc);
        if ($type === 'purchase_return') {
            $invoice = DB::table('purchase_invoices')->find($doc->invoice_id);
            $party = $invoice ? ['type' => 'supplier', 'data' => DB::table('providers')->find($invoice->provider_id)] : null;
            $branchId = $invoice->branch_id ?? null;
            $doc->original_purchase_reference = $invoice->document_number ?? null;
            $doc->product_name = DB::table('products')->where('id', $doc->product_id)->value('name');
            $doc->batch_number = DB::table('product_batches')->where('id', $doc->batch_id)->value('batch_number');
            $doc->historical_inventory_value_removed = round((float) $doc->quantity * (float) $doc->cost_price, 2);
            $doc->supplier_credit_total = (float) $doc->line_total_return;
            $doc->adjustment_amount = round($doc->historical_inventory_value_removed - $doc->supplier_credit_total, 2);
        }
        if (in_array($type, ['receipt', 'customer_receipt'], true)) {
            $doc->cheque = DB::table('checks')->where('receipt_voucher_id', $doc->id)->first();
        }
        if (in_array($type, ['payment', 'supplier_payment'], true)) {
            $doc->cheque = DB::table('checks')->where('payment_voucher_id', $doc->id)->first();
        }
        if ($type === 'cheque') {
            $party = $doc->direction === 'in' && $doc->customer_id ? ['type' => 'customer', 'data' => DB::table('customers')->find($doc->customer_id)] : ($doc->provider_id ? ['type' => 'supplier', 'data' => DB::table('providers')->find($doc->provider_id)] : null);
            $doc->related_reference = app(DocumentReferenceService::class)->resolve($doc->reference_type, $doc->reference_id ?: (null));
        }
        if ($type === 'expense') {
            $doc->account_name = DB::table('accounts')->where('id', $doc->expense_account_id)->value('name');
        }
        if ($type === 'other_income') {
            $doc->account_name = DB::table('accounts')->where('id', $doc->account_id)->value('name');
        }
        if ($type === 'stock_adjustment') {
            $doc->product_name = DB::table('products')->where('id', $doc->product_id)->value('name');
            $doc->batch_number = DB::table('product_batches')->where('id', $doc->batch_id)->value('batch_number');
        }
        if ($type === 'stock_transfer') {
            $doc->source_branch_name = DB::table('branches')->where('id', $doc->source_branch_id)->value('name');
            $doc->destination_branch_name = DB::table('branches')->where('id', $doc->destination_branch_id)->value('name');
        }
        $actor = $doc->user_id ?? $doc->created_by ?? $doc->cashier_id ?? null;
        if ($actor) {
            $doc->created_by_name = DB::table('users')->where('id', $actor)->value('name');
        }

        return [$doc, $lines, $party, $branchId];
    }

    private function saleDocument($doc, $lines, $party, array $meta, int $id): array
    {
        $cashier = isset($doc->user_id) ? DB::table('users')->find($doc->user_id) : null;
        $meta['customer'] = $party['data'] ?? null;
        $meta['generated_by'] = $cashier ? ['id' => $cashier->id, 'name' => $cashier->name] : null;
        $items = $lines->map(fn ($line) => [
            'product' => $line->product_name ?? 'Product', 'quantity' => (float) ($line->transaction_quantity ?? $line->amount ?? 0),
            'unit' => $line->unit_name ?? $line->unit_symbol ?? 'Base unit', 'packaging_quantity' => (float) ($line->base_quantity ?? $line->amount ?? 0),
            'batch' => $line->batch_number ?? null, 'unit_price' => (float) ($line->applied_unit_price ?? $line->price ?? 0),
            'discount' => (float) ($line->discount ?? 0), 'line_total' => (float) ($line->final_total ?? $line->line_total ?? 0),
        ])->values();
        $subtotal = (float) $lines->sum(fn ($line) => (float) ($line->line_total ?? 0) + (float) ($line->discount ?? 0));
        $lineDiscounts = (float) $lines->sum(fn ($line) => (float) ($line->discount ?? 0));
        $invoiceDiscount = (float) ($doc->discount ?? 0);
        $total = (float) ($doc->total_price ?? 0);
        $paid = (float) ($doc->paid_amount ?? 0);

        return ['meta' => $meta, 'content' => [
            'items' => $items,
            'totals' => ['subtotal' => $subtotal, 'line_discounts' => $lineDiscounts, 'invoice_discount' => $invoiceDiscount, 'discount' => $lineDiscounts + $invoiceDiscount, 'total' => $total, 'paid' => $paid, 'balance_due' => max(0, $total - $paid), 'payment_method' => $doc->payment_method ?? null],
            'source_references' => [['type' => 'sales_invoice', 'id' => $id]],
        ]];
    }

    private function salesReturnDocument($doc, array $meta, int $id): array
    {
        $row = DB::table('invoice_returns as returns')
            ->join('sales_invoices as invoices', 'invoices.id', '=', 'returns.invoice_id')
            ->join('products', 'products.id', '=', 'returns.product_id')
            ->join('product_batches as batches', 'batches.id', '=', 'returns.batch_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->leftJoin('branches', 'branches.id', '=', 'invoices.branch_id')
            ->leftJoin('users', 'users.id', '=', 'returns.created_by')
            ->leftJoin('prod_sales as original_lines', function ($join) {
                $join->on('original_lines.sales_invoice_id', '=', 'returns.invoice_id')->on('original_lines.batch_id', '=', 'returns.batch_id');
            })
            ->leftJoin('units', 'units.id', '=', 'original_lines.transaction_unit_id')
            ->where('returns.id', $id)
            ->select([
                'returns.*', 'invoices.document_number as source_document_number', 'products.name as product_name',
                'batches.batch_number', 'units.name as unit_name', 'units.symbol as unit_symbol',
                'customers.id as customer_id', 'customers.name as customer_name', 'branches.id as branch_id',
                'branches.name as branch_name', 'branches.code as branch_code', 'users.id as creator_id', 'users.name as creator_name',
            ])->first();
        abort_unless($row, 404);
        $originalUnit = (float) ($row->original_returnable_unit_amount ?? $row->price ?? 0);
        $creditUnit = (float) ($row->customer_credit_unit_amount ?? $row->price ?? 0);
        $quantity = (float) $row->quantity;
        $meta['branch'] = $row->branch_id ? ['id' => $row->branch_id, 'name' => $row->branch_name, 'code' => $row->branch_code] : null;
        $meta['customer'] = $row->customer_id ? ['id' => $row->customer_id, 'name' => $row->customer_name] : null;
        $meta['generated_by'] = $row->creator_id ? ['id' => $row->creator_id, 'name' => $row->creator_name] : null;
        $meta['date'] = $row->created_at;

        return ['meta' => $meta, 'content' => [
            'original_sale' => ['document_number' => $row->source_document_number],
            'items' => [[
                'product' => $row->product_name, 'batch' => $row->batch_number, 'unit' => $row->unit_name ?? $row->unit_symbol ?? null,
                'quantity' => $quantity, 'original_unit_amount' => $originalUnit, 'gross_original_value' => $quantity * $originalUnit,
                'customer_credit_unit_amount' => $creditUnit, 'customer_credit_value' => $quantity * $creditUnit,
                'deduction' => $quantity * ($originalUnit - $creditUnit), 'return_to_inventory' => (bool) $row->return_to_inventory,
            ]],
            'totals' => [
                'gross_original_return_total' => $quantity * $originalUnit,
                'customer_credit_total' => $quantity * $creditUnit,
                'return_deduction_total' => $quantity * ($originalUnit - $creditUnit),
            ],
        ]];
    }

    private function groupedSalesReturnDocument($header, array $meta): array
    {
        $document = app(InvoiceReturnService::class)->salesShow((int) $header->id);
        $meta['branch'] = $document->branch;
        $meta['customer'] = $document->customer;
        $meta['generated_by'] = ['id' => $document->user_id, 'name' => $document->user_name];
        $meta['date'] = $document->return_date;
        $items = collect($document->lines)->map(fn ($line) => [
            'product' => $line->product_name, 'batch' => $line->batch_number, 'unit' => $line->unit_name ?? null, 'quantity' => (float) $line->quantity,
            'original_unit_amount' => (float) ($line->original_returnable_unit_amount ?? $line->price),
            'gross_original_value' => (float) $line->gross_original_return_value, 'customer_credit_unit_amount' => (float) ($line->customer_credit_unit_amount ?? $line->price),
            'customer_credit_value' => (float) $line->customer_credit_value, 'deduction' => (float) $line->return_deduction, 'return_to_inventory' => (bool) $line->return_to_inventory,
        ])->values();

        return ['meta' => $meta, 'content' => [
            'original_sale' => ['document_number' => $document->source_document_number], 'items' => $items,
            'totals' => ['gross_original_return_total' => $document->totals->gross_original_return_total, 'customer_credit_total' => $document->totals->customer_credit_total, 'return_deduction_total' => $document->totals->return_deduction_total],
        ]];
    }

    private function party($d)
    {
        if (isset($d->customer_id)) {
            return ['type' => 'customer', 'data' => DB::table('customers')->find($d->customer_id)];
        }if (isset($d->provider_id)) {
            return ['type' => 'supplier', 'data' => DB::table('providers')->find($d->provider_id)];
        }

        return null;
    }

    private function totals(string $type, $d, $lines): array
    {
        return ['row_count' => $lines->count(), 'subtotal' => (float) ($d->total_before_discount ?? $d->total_price ?? $d->amount ?? 0), 'discount' => (float) ($d->discount ?? $d->descount ?? $d->invoice_discount ?? 0), 'total' => (float) ($d->total_after_discount ?? $d->total_price ?? $d->amount ?? 0), 'paid' => (float) ($d->paid_amount ?? 0), 'expected_cash' => $d->expected_cash ?? null, 'counted_cash' => $d->counted_cash ?? null, 'difference' => $d->difference ?? null];
    }

    private function redactCostFields(object $document, $lines): array
    {
        $costFields = [
            'purchase_price', 'unit_cost', 'cost_price', 'cost_basis',
            'historical_inventory_value_removed', 'adjustment_amount',
        ];
        foreach ($costFields as $field) {
            unset($document->{$field});
        }

        $lines = collect($lines)->map(function ($line) use ($costFields) {
            foreach ($costFields as $field) {
                if (is_object($line)) {
                    unset($line->{$field});
                } elseif (is_array($line)) {
                    unset($line[$field]);
                }
            }

            return $line;
        });

        return [$document, $lines];
    }

    private function meta(Request $r, string $title, $number, $status, $branch, $date, $from = null, $to = null): array
    {
        return ['branch' => $branch ? DB::table('branches')->find($branch) : null, 'title' => $title, 'document_number' => $number, 'status' => $status, 'generated_at' => now()->toISOString(), 'generated_by' => ['id' => $r->user()->id, 'name' => $r->user()->name], 'date' => $date, 'from' => $from, 'to' => $to, 'currency' => config('app.currency', 'ILS'), 'suggested_orientation' => 'portrait'];
    }

    private function authorizeOperationalPrint(Request $request, string $type, bool $report): void
    {
        if ($request->user()->hasPermission('reports.print')) {
            return;
        }
        abort_unless($request->user()->hasPermission('reports.print_operational'), 403);
        $allowed = $report
            ? ['product_statement', 'batch_statement']
            : ['sale', 'sales_invoice', 'purchase', 'purchase_invoice', 'sales_return', 'purchase_return', 'stock_transfer', 'stock_adjustment', 'stock_count', 'opening_balance'];
        abort_unless(in_array($type, $allowed, true), 403);
    }
}
