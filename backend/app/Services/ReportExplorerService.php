<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportExplorerService
{
    public const SELECTOR_REPORTS = [
        'stock_movements',
        'product_statement',
        'batch_statement',
        'ar_aging',
        'ap_aging',
        'controlled_medicine_ledger',
    ];

    private const REPORTS = [
        'units' => ['table' => 'units', 'label' => 'Units', 'columns' => ['id', 'name', 'symbol', 'created_at'], 'filters' => ['search' => 'string'], 'sort' => ['id', 'name'], 'permission' => 'reports.view_operational'],
        'product_units' => ['table' => 'product_units', 'label' => 'Product units', 'columns' => ['id', 'product_id', 'unit_id', 'base_quantity', 'sale_price', 'is_purchase_unit', 'is_sale_unit', 'is_active', 'created_at'], 'filters' => ['product_id' => 'integer', 'unit_id' => 'integer', 'is_active' => 'boolean'], 'sort' => ['id', 'product_id', 'created_at'], 'permission' => 'reports.view_operational'],
        'price_overrides' => ['table' => 'prod_sales', 'label' => 'Discounts and price overrides', 'columns' => ['id', 'sales_invoice_id', 'batch_id', 'original_unit_price', 'applied_unit_price', 'discount_type', 'discount_value', 'discount_reason', 'price_overridden', 'override_actor_id', 'override_reason', 'created_at'], 'filters' => ['price_overridden' => 'boolean', 'discount_type' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'created_at'], 'permission' => 'reports.view_financial'],
        'cashier_shifts' => ['table' => 'cashier_shifts', 'label' => 'Cashier shifts', 'columns' => ['id', 'cashier_id', 'branch_id', 'status', 'opening_cash', 'expected_cash', 'counted_cash', 'difference', 'opened_at', 'closed_at'], 'filters' => ['cashier_id' => 'integer', 'branch_id' => 'integer', 'status' => 'string'], 'sort' => ['id', 'opened_at', 'closed_at'], 'permission' => 'reports.view_financial'],
        'other_income' => ['table' => 'other_incomes', 'label' => 'Other income', 'columns' => ['id', 'document_number', 'date', 'branch_id', 'account_id', 'amount', 'payment_method', 'description', 'reference', 'status', 'user_id'], 'filters' => ['branch_id' => 'integer', 'payment_method' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'date', 'amount'], 'permission' => 'reports.view_financial'],
        'stock_counts' => ['table' => 'stock_counts', 'label' => 'Stock counts', 'columns' => ['id', 'document_number', 'branch_id', 'scope', 'status', 'created_by', 'posted_by', 'snapshot_at', 'posted_at'], 'filters' => ['branch_id' => 'integer', 'status' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'snapshot_at', 'posted_at'], 'permission' => 'reports.view_operational'],
        'stock_adjustments' => ['table' => 'stock_adjustments', 'label' => 'Stock adjustments', 'columns' => ['id', 'document_number', 'branch_id', 'product_id', 'batch_id', 'quantity', 'reason', 'user_id', 'posted_at'], 'filters' => ['branch_id' => 'integer', 'product_id' => 'integer', 'batch_id' => 'integer', 'user_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'posted_at'], 'permission' => 'reports.view_operational'],
        'stock_transfers' => ['table' => 'stock_transfers', 'label' => 'Stock transfers', 'columns' => ['id', 'document_number', 'source_branch_id', 'destination_branch_id', 'status', 'created_by', 'sent_at', 'received_at'], 'filters' => ['status' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'created_at', 'sent_at'], 'permission' => 'reports.view_operational'],
        'bank_reconciliation' => ['table' => 'bank_reconciliations', 'label' => 'Bank reconciliation', 'columns' => ['id', 'account_id', 'journal_entry_line_id', 'source_type', 'source_id', 'reconciled', 'reconciled_at', 'reconciled_by', 'external_reference'], 'filters' => ['account_id' => 'integer', 'reconciled' => 'boolean', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'reconciled_at'], 'permission' => 'reports.view_financial'],
        'settlement_allocations' => ['table' => 'settlement_allocations', 'label' => 'Settlement allocations', 'columns' => ['id', 'side', 'settlement_type', 'settlement_id', 'invoice_type', 'invoice_id', 'amount', 'created_by', 'created_at'], 'filters' => ['side' => 'string', 'settlement_type' => 'string', 'invoice_type' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'created_at', 'amount'], 'permission' => 'reports.view_financial'],
        'product_statement' => ['virtual' => true, 'label' => 'Product Statement', 'columns' => ['id', 'created_at', 'movement_type', 'quantity', 'quantity_in', 'quantity_out', 'source_type', 'source_id', 'source_label', 'user_id', 'user_name', 'product_id', 'product_name', 'product_barcode', 'batch_id', 'batch_number', 'batch_expiry', 'branch', 'cost_basis'], 'default_columns' => ['created_at', 'movement_type', 'quantity', 'quantity_in', 'quantity_out', 'source_type', 'source_label', 'user_name', 'product_name', 'product_barcode', 'batch_number', 'batch_expiry', 'branch', 'cost_basis'], 'filters' => ['product_id' => 'integer', 'branch_id' => 'integer', 'from' => 'date', 'to' => 'date', 'movement_type' => 'string'], 'required_filters' => ['product_id'], 'sort' => ['created_at', 'id', 'movement_type'], 'default_direction' => 'desc', 'permission' => 'reports.view_operational', 'summary' => ['opening_quantity', 'closing_quantity', 'sold_quantity', 'purchased_quantity']],
        'batch_statement' => ['virtual' => true, 'label' => 'Batch Statement', 'columns' => ['id', 'created_at', 'movement_type', 'quantity', 'quantity_in', 'quantity_out', 'source_type', 'source_id', 'source_label', 'user_id', 'user_name', 'product_id', 'product_name', 'product_barcode', 'batch_id', 'batch_number', 'batch_expiry', 'branch', 'cost_basis'], 'default_columns' => ['created_at', 'movement_type', 'quantity', 'quantity_in', 'quantity_out', 'source_type', 'source_label', 'user_name', 'product_name', 'product_barcode', 'batch_number', 'batch_expiry', 'branch', 'cost_basis'], 'filters' => ['product_id' => 'integer', 'batch_id' => 'integer', 'branch_id' => 'integer', 'from' => 'date', 'to' => 'date', 'movement_type' => 'string'], 'required_filters' => ['batch_id'], 'sort' => ['created_at', 'id', 'movement_type'], 'default_direction' => 'desc', 'permission' => 'reports.view_operational', 'summary' => ['opening_quantity', 'closing_quantity']],
        'customer_statement' => ['virtual' => true, 'label' => 'Customer Statement', 'columns' => ['entry_date', 'entry_number', 'source_type', 'source_id', 'source_label', 'debit', 'credit', 'running_balance', 'payment_method', 'cheque_id'], 'filters' => ['customer_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['entry_date', 'entry_number'], 'permission' => 'reports.view_financial', 'summary' => ['opening_balance', 'total_debit', 'total_credit', 'closing_balance']],
        'supplier_statement' => ['virtual' => true, 'label' => 'Supplier Statement', 'columns' => ['entry_date', 'entry_number', 'source_type', 'source_id', 'source_label', 'debit', 'credit', 'running_balance', 'payment_method', 'cheque_id'], 'filters' => ['provider_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['entry_date', 'entry_number'], 'permission' => 'reports.view_financial', 'summary' => ['opening_balance', 'total_debit', 'total_credit', 'closing_balance']],
        'ar_aging' => ['virtual' => true, 'label' => 'Accounts Receivable Aging', 'columns' => ['party_id', 'party_name', 'aging_basis', 'invoice_id', 'invoice_number', 'invoice_date', 'due_date', 'original_amount', 'settled_amount', 'outstanding_amount', 'age_days', 'bucket'], 'default_columns' => ['party_name', 'invoice_number', 'invoice_date', 'due_date', 'original_amount', 'settled_amount', 'outstanding_amount', 'age_days', 'bucket'], 'filters' => ['as_of' => 'date', 'branch_id' => 'integer', 'party_id' => 'integer', 'minimum_balance' => 'decimal', 'outstanding_only' => 'boolean', 'detail' => 'boolean'], 'sort' => ['due_date', 'outstanding_amount', 'party_name'], 'permission' => 'reports.view_financial', 'summary' => ['total_outstanding']],
        'ap_aging' => ['virtual' => true, 'label' => 'Accounts Payable Aging', 'columns' => ['party_id', 'party_name', 'aging_basis', 'invoice_id', 'invoice_number', 'invoice_date', 'due_date', 'original_amount', 'settled_amount', 'outstanding_amount', 'age_days', 'bucket'], 'default_columns' => ['party_name', 'invoice_number', 'invoice_date', 'due_date', 'original_amount', 'settled_amount', 'outstanding_amount', 'age_days', 'bucket'], 'filters' => ['as_of' => 'date', 'branch_id' => 'integer', 'party_id' => 'integer', 'minimum_balance' => 'decimal', 'outstanding_only' => 'boolean', 'detail' => 'boolean'], 'sort' => ['due_date', 'outstanding_amount', 'party_name'], 'permission' => 'reports.view_financial', 'summary' => ['total_outstanding']],
        'controlled_medicine_ledger' => ['virtual' => true, 'label' => 'Controlled Medicine Ledger', 'columns' => ['id', 'timestamp', 'product_id', 'product', 'batch_id', 'batch_number', 'branch_id', 'type', 'in', 'out', 'reference_type', 'reference_id', 'source_label', 'actor', 'disposition', 'patient', 'prescription', 'prescriber'], 'filters' => ['product_id' => 'integer', 'batch_id' => 'integer', 'branch_id' => 'integer', 'movement_type' => 'string', 'user_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['timestamp', 'id', 'product'], 'permission' => 'controlled.view', 'summary' => ['opening', 'received', 'sold', 'prescription_dispensed', 'returns', 'adjustments', 'transfers', 'closing']],
        'recall_traceability' => ['virtual' => true, 'label' => 'Recall Traceability', 'columns' => ['movement_group', 'purchase_id', 'sale_id', 'document_number', 'supplier_id', 'supplier', 'customer_id', 'customer', 'quantity', 'prescription_id', 'patient_id', 'patient_name'], 'filters' => ['recall_id' => 'integer'], 'sort' => ['movement_group', 'document_number'], 'permission' => 'reports.view_operational', 'summary' => ['purchased_quantity', 'current_quantity', 'sales_quantity', 'linkage_complete']],
        'products' => ['table' => 'products', 'label' => 'Products', 'columns' => ['id', 'name', 'barcode', 'is_controlled', 'control_schedule', 'minimum_stock', 'reorder_level', 'reorder_quantity', 'created_at'], 'filters' => ['search' => 'string', 'is_controlled' => 'boolean'], 'sort' => ['id', 'name', 'created_at'], 'permission' => 'reports.view_operational'],
        'batches' => ['table' => 'product_batches', 'label' => 'Batches', 'columns' => ['id', 'product_id', 'branch_id', 'batch_number', 'expiry_date', 'quantity', 'purchase_price', 'sale_price', 'inventory_state', 'created_at'], 'filters' => ['product_id' => 'integer', 'branch_id' => 'integer', 'inventory_state' => 'enum', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'expiry_date', 'quantity', 'created_at'], 'permission' => 'reports.view_operational'],
        'stock_movements' => ['table' => 'stock', 'label' => 'Stock movements', 'columns' => ['created_at', 'product_name', 'product_barcode', 'batch_number', 'batch_expiry', 'branch', 'type', 'quantity', 'unit_cost', 'reference_type', 'reference', 'user_name', 'movement_id', 'id', 'batch_id', 'branch_id', 'reference_id', 'user_id'], 'default_columns' => ['created_at', 'product_name', 'product_barcode', 'batch_number', 'batch_expiry', 'branch', 'type', 'quantity', 'unit_cost', 'reference_type', 'reference', 'user_name', 'movement_id'], 'filters' => ['product_id' => 'integer', 'batch_id' => 'integer', 'branch_id' => 'integer', 'type' => 'string', 'reference_type' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['created_at', 'movement_id', 'id', 'product_name', 'batch_number'], 'default_direction' => 'desc', 'permission' => 'reports.view_operational'],
        'customers' => ['table' => 'customers', 'label' => 'Customers', 'columns' => ['id', 'name', 'phone_num', 'city', 'credit_limit', 'block_on_limit', 'created_at'], 'filters' => ['search' => 'string'], 'sort' => ['id', 'name', 'created_at'], 'permission' => 'reports.view_operational'],
        'suppliers' => ['table' => 'providers', 'label' => 'Suppliers', 'columns' => ['id', 'name', 'phone_num', 'city', 'created_at'], 'filters' => ['search' => 'string'], 'sort' => ['id', 'name', 'created_at'], 'permission' => 'reports.view_operational'],
        'sales' => ['table' => 'sales_invoices', 'label' => 'Sales', 'columns' => ['id', 'document_number', 'customer_id', 'user_id', 'date', 'total_price', 'paid_amount', 'payment_method', 'profit', 'created_at'], 'filters' => ['customer_id' => 'integer', 'payment_method' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'date', 'total_price'], 'permission' => 'reports.view_financial'],
        'purchases' => ['table' => 'purchase_invoices', 'label' => 'Purchases', 'columns' => ['id', 'document_number', 'provider_id', 'user_id', 'date', 'amount', 'status', 'created_at'], 'filters' => ['provider_id' => 'integer', 'status' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'date', 'amount'], 'permission' => 'reports.view_financial'],
        'sales_returns' => ['table' => 'invoice_returns', 'label' => 'Sales returns', 'columns' => ['id', 'document_number', 'invoice_id', 'product_id', 'batch_id', 'quantity', 'price', 'reason', 'user_id', 'created_at'], 'filters' => ['invoice_id' => 'integer', 'product_id' => 'integer', 'batch_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'created_at'], 'permission' => 'reports.view_financial'],
        'purchase_returns' => ['table' => 'purch_returns', 'label' => 'Purchase returns', 'columns' => ['id', 'document_number', 'invoice_id', 'product_id', 'batch_id', 'quantity', 'line_total_return', 'reason', 'user_id', 'created_at'], 'filters' => ['invoice_id' => 'integer', 'product_id' => 'integer', 'batch_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'created_at'], 'permission' => 'reports.view_financial'],
        'receipts' => ['table' => 'receipt_vouchers', 'label' => 'Receipts', 'columns' => ['id', 'document_number', 'customer_id', 'amount', 'payment_method', 'date', 'status'], 'filters' => ['customer_id' => 'integer', 'payment_method' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'date', 'amount'], 'permission' => 'reports.view_financial'],
        'payments' => ['table' => 'payment_vouchers', 'label' => 'Payments', 'columns' => ['id', 'document_number', 'provider_id', 'amount', 'payment_method', 'date', 'status'], 'filters' => ['provider_id' => 'integer', 'payment_method' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'date', 'amount'], 'permission' => 'reports.view_financial'],
        'cheques' => ['table' => 'checks', 'label' => 'Cheques', 'columns' => ['id', 'check_number', 'bank_name', 'amount', 'due_date', 'direction', 'status', 'customer_id', 'provider_id', 'reference_type', 'reference_id', 'created_at', 'cleared_at', 'bounced_at'], 'filters' => ['direction' => 'string', 'status' => 'string', 'customer_id' => 'integer', 'provider_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'due_date', 'amount', 'created_at'], 'permission' => 'reports.view_financial'],
        'prescriptions' => ['table' => 'prescriptions', 'label' => 'Prescriptions', 'columns' => ['id', 'patient_id', 'prescriber_name', 'branch_id', 'status', 'prescribed_at', 'expires_at', 'created_by'], 'filters' => ['branch_id' => 'integer', 'status' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'prescribed_at'], 'permission' => 'reports.view_operational'],
        'expenses' => ['table' => 'expenses', 'label' => 'Expenses', 'columns' => ['id', 'document_number', 'branch_id', 'expense_account_id', 'amount', 'type', 'payment_method', 'date', 'description', 'external_reference', 'notes', 'status', 'user_id'], 'filters' => ['type' => 'string', 'payment_method' => 'string', 'branch_id' => 'integer', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'date', 'amount'], 'permission' => 'reports.view_financial'],
        'general_ledger' => ['table' => 'journal_entries', 'label' => 'General ledger entries', 'columns' => ['id', 'entry_number', 'entry_date', 'description', 'status', 'voucher_id', 'accounting_period_id', 'total_debit', 'total_credit', 'created_by', 'posted_at', 'created_at'], 'filters' => ['status' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'entry_date', 'entry_number'], 'permission' => 'reports.view_financial'],
        'audit_users' => ['table' => 'audit_logs', 'label' => 'Audit log', 'columns' => ['id', 'user_id', 'action_type', 'table_name', 'record_id', 'created_at'], 'filters' => ['user_id' => 'integer', 'action_type' => 'string', 'table_name' => 'string', 'from' => 'date', 'to' => 'date'], 'sort' => ['id', 'created_at'], 'permission' => 'audits.view'],
    ];

    public function catalog($user): array
    {
        return collect(self::SELECTOR_REPORTS)->mapWithKeys(fn ($key) => [$key => self::REPORTS[$key]])->filter(fn ($r) => $user->hasPermission($r['permission']))->map(fn ($r, $key) => ['key' => $key, 'label' => $r['label'], 'filters' => collect($r['filters'])->map(fn ($type, $name) => ['key' => $name, 'type' => $type])->values(), 'allowed_filters' => $r['filters'], 'required_filters' => $r['required_filters'] ?? [], 'sortable_columns' => $r['sort'], 'sortable_fields' => $r['sort'], 'default_direction' => $r['default_direction'] ?? 'asc', 'allowed_columns' => $this->allowedColumns($r['columns'], $user), 'available_columns' => $this->allowedColumns($r['columns'], $user), 'default_columns' => $this->allowedColumns($r['default_columns'] ?? $r['columns'], $user), 'summary_fields' => $r['summary'] ?? [], 'summary_metrics' => $r['summary'] ?? [], 'required_permission' => $r['permission'], 'printable' => true, 'exportable' => true, 'virtual' => (bool) ($r['virtual'] ?? false)])->values()->all();
    }

    public function definition(string $key): array
    {
        return self::REPORTS[$key] ?? throw ValidationException::withMessages(['report_key' => 'Unknown report.']);
    }

    public function isSelectable(string $key): bool
    {
        return in_array($key, self::SELECTOR_REPORTS, true);
    }

    public function query(array $input, $user): array
    {
        $r = $this->definition($input['report_key']);
        abort_unless($user->hasPermission($r['permission']), 403);
        $allowed = $this->allowedColumns($r['columns'], $user);
        $columns = $input['columns'] ?? $this->allowedColumns($r['default_columns'] ?? $r['columns'], $user);
        if (array_diff($columns, $allowed)) {
            throw ValidationException::withMessages(['columns' => 'Unsupported or unauthorized column.']);
        }
        $sort = $input['sort'] ?? $r['sort'][0];
        if (! in_array($sort, $r['sort'], true)) {
            throw ValidationException::withMessages(['sort' => 'Unsupported sort.']);
        }
        $this->validateFilters($r, $input['filters'] ?? []);
        if ($r['virtual'] ?? false) {
            $virtualFilters = array_merge($input['filters'] ?? [], ['page' => $input['page'] ?? 1, 'per_page' => $input['per_page'] ?? 25]);
            $x = app(VirtualReportService::class)->execute($input['report_key'], $virtualFilters, $user);
            $rows = collect($x['rows'])->map(fn ($row) => collect((array) $row)->only($columns)->all())->sortBy($sort, SORT_REGULAR, ($input['direction'] ?? 'asc') === 'desc')->values();

            return ['rows' => $rows, 'meta' => $x['meta'], 'columns' => $columns, 'summary' => $x['summary'] ?? [], 'report' => ['key' => $input['report_key'], 'label' => $r['label']]];
        }
        $stockScreenColumns = $r['table'] === 'stock' ? array_values(array_intersect([
            'created_at', 'product_name', 'product_barcode', 'product_id', 'batch_number', 'batch_expiry', 'batch_id',
            'branch', 'branch_id', 'type', 'quantity', 'unit_cost', 'reference_type', 'reference', 'user_name', 'movement_id',
        ], $allowed)) : [];
        $selectColumns = $r['table'] === 'stock' ? array_values(array_unique([...$columns, ...$stockScreenColumns, 'branch_code', 'reference_id'])) : $columns;
        $q = $this->builder($r, $input['filters'] ?? [])->select($selectColumns);
        $direction = ($input['direction'] ?? $r['default_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $q->orderBy($sort, $direction);
        if ($r['table'] === 'stock' && ! in_array($sort, ['movement_id', 'id'], true)) {
            $q->orderBy('movement_id', $direction);
        }$p = $q->paginate(min(100, max(1, (int) ($input['per_page'] ?? 25))));
        $items = $this->presentRows($r['table'], $this->localizeBusinessTimestamps($r['table'], collect($p->items())))->all();

        return ['rows' => $items, 'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()], 'columns' => $columns, 'report' => ['key' => $input['report_key'], 'label' => $r['label']]];
    }

    public function export(array $input, $user)
    {
        $r = $this->definition($input['report_key']);
        abort_unless($user->hasPermission($r['permission']) && $user->hasPermission('reports.export'), 403);
        $allowed = $this->allowedColumns($r['columns'], $user);
        $columns = $input['columns'] ?? $this->allowedColumns($r['default_columns'] ?? $r['columns'], $user);
        if (array_diff($columns, $allowed)) {
            throw ValidationException::withMessages(['columns' => 'Unsupported or unauthorized column.']);
        }
        $this->validateFilters($r, $input['filters'] ?? []);
        $headers = $this->csvHeaders($input['report_key'], $columns);
        if ($r['virtual'] ?? false) {
            $x = app(VirtualReportService::class)->execute($input['report_key'], $input['filters'] ?? [], $user, true);
            $rows = collect($x['rows'])->map(fn ($row) => collect((array) $row)->only($columns)->all());
            $rows = $this->presentCsvRows($input['report_key'], $rows);

            return response()->streamDownload(function () use ($rows, $headers) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $headers);
                $rows->each(fn ($row) => fputcsv($out, $row));
                fclose($out);
            }, $input['report_key'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
        }$selectColumns = $r['table'] === 'stock' ? array_values(array_unique([...$columns, 'branch_code'])) : $columns;
        $sort = $input['sort'] ?? $r['sort'][0];
        $direction = $input['direction'] ?? $r['default_direction'] ?? 'asc';
        $q = $this->builder($r, $input['filters'] ?? [])->select($selectColumns)->orderBy($sort, $direction);
        if ($r['table'] === 'stock' && ! in_array($sort, ['movement_id', 'id'], true)) {
            $q->orderBy('movement_id', $direction);
        }$table = $r['table'];

        return response()->streamDownload(function () use ($q, $columns, $headers, $table) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            $q->chunk(500, function ($rows) use ($out, $columns, $table) {
                $this->presentRows($table, $rows)->each(fn ($row) => fputcsv($out, collect((array) $row)->only($columns)->all()));
            });
            fclose($out);
        }, $input['report_key'].'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function builder(array $r, array $filters): Builder
    {
        $q = $r['table'] === 'stock' ? $this->stockMovementProjection() : DB::table($r['table']);
        if (in_array($r['table'], ['stock', 'stock_adjustments'], true)) {
            $column = $r['table'] === 'stock' ? 'created_at' : 'posted_at';
            app(BusinessTimeService::class)->applyUtcDateFilter($q, $column, $filters['from'] ?? null, $filters['to'] ?? null);
            unset($filters['from'], $filters['to']);
        }
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            } if (! isset($r['filters'][$key])) {
                throw ValidationException::withMessages(["filters.$key" => 'Unsupported filter.']);
            } if ($key === 'search') {
                $q->where(fn ($s) => $s->where('name', 'like', '%'.$value.'%'));
            } elseif (in_array($key, ['from', 'to'], true)) {
                $dateColumn = in_array($r['table'], ['sales_invoices', 'purchase_invoices', 'receipt_vouchers', 'payment_vouchers', 'expenses', 'other_incomes'], true) ? 'date' : (in_array($r['table'], ['journal_entries'], true) ? 'entry_date' : (in_array($r['table'], ['stock_counts'], true) ? 'snapshot_at' : (in_array($r['table'], ['stock_adjustments'], true) ? 'posted_at' : (in_array($r['table'], ['batch_recalls'], true) ? 'issued_at' : 'created_at'))));
                $q->whereDate($dateColumn, $key === 'from' ? '>=' : '<=', $value);
            } else {
                $q->where($key, $value);
            }
        }

        return $q;
    }

    public function printData(array $input, $user): array
    {
        $r = $this->definition($input['report_key']);
        abort_unless($user->hasPermission($r['permission']) && $user->hasPermission('reports.print'), 403);
        $allowed = $this->allowedColumns($r['columns'], $user);
        $columns = $input['columns'] ?? $this->allowedColumns($r['default_columns'] ?? $r['columns'], $user);
        if (array_diff($columns, $allowed)) {
            throw ValidationException::withMessages(['columns' => 'Unsupported or unauthorized column.']);
        }$sort = $input['sort'] ?? $r['sort'][0];
        if (! in_array($sort, $r['sort'], true)) {
            throw ValidationException::withMessages(['sort' => 'Unsupported sort.']);
        }$direction = $input['direction'] ?? $r['default_direction'] ?? 'asc';
        $this->validateFilters($r, $input['filters'] ?? []);
        if ($r['virtual'] ?? false) {
            $x = app(VirtualReportService::class)->execute($input['report_key'], $input['filters'] ?? [], $user, true);
            $all = collect($x['rows']);
            $total = $all->count();
            $rows = $all->take(5000)->map(fn ($row) => collect((array) $row)->only($columns)->all());
            $summary = $x['summary'] ?? [];
        } else {
            $base = $this->builder($r, $input['filters'] ?? []);
            $total = (clone $base)->count();
            $selectColumns = $r['table'] === 'stock' ? array_values(array_unique([...$columns, 'branch_code'])) : $columns;
            $query = $base->select($selectColumns)->orderBy($sort, $direction);
            if ($r['table'] === 'stock' && ! in_array($sort, ['movement_id', 'id'], true)) {
                $query->orderBy('movement_id', $direction);
            }$rows = $total > 5000 ? collect() : $this->presentRows($r['table'], $query->get())->map(fn ($row) => collect((array) $row)->only($columns)->all());
            $summary = [];
        }
        $summary = collect($summary)->except(['meta', 'data', 'row_count', 'balance_semantics', 'period_change'])->all();
        if ($total > 5000) {
            return ['print_too_large' => true, 'total_rows' => $total, 'max_rows' => 5000, 'export_csv_available' => true, 'rows' => [], 'report' => ['key' => $input['report_key'], 'label' => $r['label']]];
        }

        return ['print_too_large' => false, 'total_rows' => $total, 'max_rows' => 5000, 'export_csv_available' => true, 'print_identity' => app(PrintIdentityService::class)->currentPrintable(), 'branch' => $input['filters']['branch_id'] ?? null, 'report_title' => $r['label'], 'generated_at' => now()->toISOString(), 'generated_by' => $user->name, 'from' => $input['filters']['from'] ?? null, 'to' => $input['filters']['to'] ?? null, 'filters' => $input['filters'] ?? [], 'column_definitions' => array_map(fn ($x) => ['key' => $x, 'label' => str($x)->replace('_', ' ')->title()->toString()], $columns), 'rows' => $rows, 'summaries' => $summary, 'opening_balance' => $summary['opening_balance'] ?? null, 'closing_balance' => $summary['closing_balance'] ?? null, 'currency' => config('app.currency', 'ILS'), 'suggested_orientation' => count($columns) > 7 ? 'landscape' : 'portrait'];
    }

    private function allowedColumns(array $columns, $user): array
    {
        return array_values(array_filter($columns, function ($c) use ($user) {
            if (in_array($c, ['purchase_price', 'unit_cost', 'cost_price', 'cost_basis'], true)) {
                return $user->hasPermission('reports.view_cost');
            }if (in_array($c, ['profit', 'gross_profit'], true)) {
                return $user->hasPermission('reports.view_profit');
            }if (in_array($c, ['patient', 'patient_id', 'patient_name', 'prescription', 'prescription_id', 'prescriber'], true)) {
                return $user->hasPermission('controlled.audit');
            }

            return true;
        }));
    }

    private function localizeBusinessTimestamps(string $table, $rows)
    {
        $column = $table === 'stock' ? 'created_at' : ($table === 'stock_adjustments' ? 'posted_at' : null);
        if (! $column) {
            return $rows;
        }

        return $rows->map(function ($row) use ($column) {
            if (isset($row->{$column})) {
                $row->{$column} = app(BusinessTimeService::class)->toBusinessTimestamp((string) $row->{$column});
            }

            return $row;
        });
    }

    private function stockMovementProjection(): Builder
    {
        $projection = DB::table('stock as movements')
            ->leftJoin('product_batches as batches', 'batches.id', '=', 'movements.batch_id')
            ->leftJoin('products as products', 'products.id', '=', 'batches.product_id')
            ->leftJoin('branches as branches', fn ($join) => $join->on('branches.id', '=', DB::raw('COALESCE(movements.branch_id, batches.branch_id)')))
            ->leftJoin('users as users', 'users.id', '=', 'movements.user_id')
            ->leftJoin('purchase_invoices as purchases', fn ($join) => $join->on('purchases.id', '=', 'movements.reference_id')->where('movements.reference_type', '=', 'purchase'))
            ->leftJoin('sales_invoices as sales', fn ($join) => $join->on('sales.id', '=', 'movements.reference_id')->whereIn('movements.reference_type', ['sale', 'sales_return', 'sales_cancel']))
            ->leftJoin('purch_returns as purchase_returns', fn ($join) => $join->on('purchase_returns.id', '=', 'movements.reference_id')->where('movements.reference_type', '=', 'purchase_return'))
            ->leftJoin('stock_adjustments as adjustments', fn ($join) => $join->on('adjustments.id', '=', 'movements.reference_id')->where('movements.reference_type', '=', 'stock_adjustment'))
            ->leftJoin('stock_counts as counts', fn ($join) => $join->on('counts.id', '=', 'movements.reference_id')->where('movements.reference_type', '=', 'stock_count'))
            ->leftJoin('stock_transfers as transfers', fn ($join) => $join->on('transfers.id', '=', 'movements.reference_id')->where('movements.reference_type', '=', 'stock_transfer'))
            ->leftJoin('opening_balance_sessions as openings', fn ($join) => $join->on('openings.id', '=', 'movements.reference_id')->where('movements.reference_type', '=', 'opening_balance'))
            ->selectRaw('movements.id, movements.id as movement_id, movements.created_at, batches.product_id, products.name as product_name, products.barcode as product_barcode, movements.batch_id, batches.batch_number, batches.expiry_date as batch_expiry, movements.branch_id, branches.code as branch_code, branches.name as branch, movements.type, movements.quantity, movements.unit_cost, movements.reference_type, movements.reference_id, COALESCE(purchases.document_number, sales.document_number, purchase_returns.document_number, adjustments.document_number, counts.document_number, transfers.document_number, openings.document_number, movements.reference_id) as reference, movements.user_id, users.name as user_name, movements.disposition');

        return DB::query()->fromSub($projection, 'stock_movements');
    }

    private function presentRows(string $table, $rows)
    {
        if ($table !== 'stock') {
            return $rows;
        }

        return collect($rows)->map(function ($row) {
            if (property_exists($row, 'product_name')) {
                $row->product_name = $row->product_name ?: __('api.unknown_product');
            }
            if (property_exists($row, 'batch_number')) {
                $row->batch_number = $row->batch_number ?: __('api.unknown_batch');
            }
            if (property_exists($row, 'branch')) {
                $row->branch = ($row->branch_code ?? null) && $row->branch ? "{$row->branch_code} — {$row->branch}" : ($row->branch ?: ($row->branch_code ?? __('api.unknown_branch')));
            }
            if (property_exists($row, 'user_name')) {
                $row->user_name = $row->user_name ?: __('api.unknown_user');
            }
            if (property_exists($row, 'reference')) {
                $row->reference = $row->reference !== null ? (string) $row->reference : '—';
            }

            return $row;
        });
    }

    private function csvHeaders(string $reportKey, array $columns): array
    {
        if (! in_array($reportKey, ['product_statement', 'batch_statement', 'ar_aging', 'ap_aging'], true)) {
            return $columns;
        }

        return array_map(fn ($column) => trans("reports.columns.$column"), $columns);
    }

    private function presentCsvRows(string $reportKey, Collection $rows): Collection
    {
        if (! in_array($reportKey, ['ar_aging', 'ap_aging'], true)) {
            return $rows;
        }

        return $rows->map(function (array $row) {
            foreach (['invoice_date', 'due_date'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = str_replace('-', '/', substr((string) $row[$key], 0, 10));
                }
            }
            if (isset($row['bucket'])) {
                $row['bucket'] = trans('reports.values.bucket.'.(string) $row['bucket']);
            }
            if (isset($row['aging_basis'])) {
                $row['aging_basis'] = trans('reports.values.aging_basis.'.(string) $row['aging_basis']);
            }

            return $row;
        });
    }

    private function validateFilters(array $r, array $f): void
    {
        foreach ($f as $k => $v) {
            if ($v !== null && $v !== '' && ! array_key_exists($k, $r['filters'])) {
                throw ValidationException::withMessages(["filters.$k" => 'Unsupported filter.']);
            }
        }
    }
}
