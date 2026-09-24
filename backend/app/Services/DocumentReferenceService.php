<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DocumentReferenceService
{
    public function resolve(?string $type, ?int $id): ?string
    {
        if (!$type || !$id) return null;

        [$table, $column] = match ($type) {
            'sale', 'sales_invoice', 'sales_cancel', 'sales_invoice_reversal' => ['sales_invoices', 'document_number'],
            'purchase', 'purchase_invoice' => ['purchase_invoices', 'document_number'],
            'purchase_return' => ['purch_returns', 'document_number'],
            'receipt' => ['receipt_vouchers', 'document_number'],
            'payment' => ['payment_vouchers', 'document_number'],
            'expense' => ['expenses', 'document_number'],
            'other_income' => ['other_incomes', 'document_number'],
            'transfer', 'stock_transfer' => ['stock_transfers', 'document_number'],
            'adjustment', 'stock_adjustment' => ['stock_adjustments', 'document_number'],
            'stock_count' => ['stock_counts', 'document_number'],
            'opening', 'opening_balance' => ['opening_balance_sessions', 'document_number'],
            'cheque', 'cheque_clear', 'cheque_bounce' => ['checks', 'check_number'],
            default => [null, null],
        };
        if ($type === 'sales_return') {
            return DB::table('sales_returns')->where('id', $id)->value('document_number')
                ?? DB::table('invoice_returns')->where('id', $id)->value('document_number');
        }
        return $table ? DB::table($table)->where('id', $id)->value($column) : null;
    }

    public function label(?string $type, ?int $id): string
    {
        return $this->resolve($type, $id)
            ?? 'Legacy '.str($type ?: 'source document')->replace('_', ' ')->lower().' (no document number)';
    }
}
