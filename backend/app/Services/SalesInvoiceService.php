<?php

namespace App\Services;

use App\Exceptions\SalesInventoryException;
use App\Models\ProductBatch;
use App\Models\Stock;
use DB;
use Exception;

class SalesInvoiceService
{
    public function index()
    {
        return DB::table('sales_invoices')->get();
    }

    public function cancel($id, int $userId)
    {
        return DB::transaction(function () use ($id, $userId) {
            $response = DB::table('sales_invoices')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $response) {
                return ['message' => 'Invoice already cancelled'];
            }

            foreach (DB::table('prod_sales')->where('sales_invoice_id', $id)->get() as $line) {
                $batch = ProductBatch::query()->whereKey($line->batch_id)->lockForUpdate()->first();
                if ($batch) {
                    $batch->quantity += (int) $line->amount;
                    $batch->save();
                }

                Stock::query()->create([
                    'batch_id' => $line->batch_id,
                    'quantity' => (int) $line->amount,
                    'type' => 'SALES_RETURN_IN',
                    'reference_type' => 'sales_cancel',
                    'reference_id' => $id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            DB::table('sales_invoices')->where('id', $id)->update(['deleted_at' => now()]);
            app(AccountingPostingService::class)->reverseEvent('sales_invoice', (int) $id, now()->toDateString(), $userId, 'Sales invoice cancellation');
            DB::table('checks')->where(['reference_type' => 'sales_invoice', 'reference_id' => $id, 'status' => 'pending'])->update(['status' => 'bounced', 'bounced_at' => now(), 'updated_at' => now()]);

            return ['message' => 'Sales invoice cancelled'];
        });
    }

    public function store($request, $user)
    {
        return DB::transaction(function () use ($request, $user) {
            if (empty($request->items)) {
                return ['message' => 'items is empty'];
            }

            $normalizedItems = [];
            $total_before = 0;
            foreach ($request->items as $item) {
                $productId = $item['product_id'] ?? (! empty($item['batch_id']) ? DB::table('product_batches')->where('id', $item['batch_id'])->value('product_id') : DB::table('products')->where('barcode', $item['barcode'] ?? '')->value('id'));
                $unit = ! empty($item['unit_id']) ? DB::table('product_units')->where(['product_id' => $productId, 'unit_id' => $item['unit_id'], 'is_active' => true, 'is_sale_unit' => true])->first() : DB::table('product_units')->where(['product_id' => $productId, 'is_base' => true, 'is_active' => true, 'is_sale_unit' => true])->first();
                if (! empty($item['unit_id']) && ! $unit) {
                    throw new \InvalidArgumentException('Invalid sale unit for product.');
                }
                $factor = (int) ($unit->base_quantity ?? 1);
                $defaultPrice = (float) ($unit->sale_price ?? ProductBatch::query()->where('product_id', $productId)->availableForSale()->value('sale_price') ?? $item['price'] ?? 0);
                $item['price'] = (float) ($item['price'] ?? $defaultPrice);
                $override = abs((float) $item['price'] - $defaultPrice) > 0.001;
                if ($override && ! $user->hasPermission('sales.override_price')) {
                    abort(403, 'Price override permission is required.');
                }
                $discountType = $item['discount_type'] ?? 'fixed';
                $discountValue = (float) ($item['discount_value'] ?? $item['discount'] ?? 0);
                $discount = $discountType === 'percentage' ? round((float) $item['price'] * (int) $item['amount'] * $discountValue / 100, 2) : $discountValue;
                if ($discount > 0 && ! $user->hasPermission('sales.discount')) {
                    abort(403, 'Discount permission is required.');
                }
                if ($discount > 0 && empty($item['discount_reason'])) {
                    throw new \InvalidArgumentException('Discount reason is required.');
                }
                $item['_factor'] = $factor;
                $item['_base_quantity'] = (int) $item['amount'] * $factor;
                $item['_transaction_quantity'] = (int) $item['amount'];
                $item['_discount'] = $discount;
                $item['_default_price'] = $defaultPrice;
                $item['_override'] = $override;
                $item['_product_id'] = $productId;
                $item['_unit_id'] = $unit->unit_id ?? null;
                $normalizedItems[] = $item;
                $total_before += ((float) $item['price'] * (int) $item['amount']) - $discount;
            }
            $invoiceDiscountType = $request['invoice_discount_type'] ?? 'fixed';
            $invoiceDiscountValue = (float) ($request['invoice_discount_value'] ?? $request['discount'] ?? 0);
            $invoiceDiscount = $invoiceDiscountType === 'percentage' ? round($total_before * $invoiceDiscountValue / 100, 2) : $invoiceDiscountValue;
            if ($invoiceDiscountType === 'percentage' && $invoiceDiscountValue > 100) {
                throw new \InvalidArgumentException('Invoice discount percentage cannot exceed 100.');
            }
            if ($invoiceDiscount > 0 && ! $user->hasPermission('sales.discount')) {
                abort(403, 'Discount permission is required.');
            }
            if ($invoiceDiscount > 0 && empty($request['invoice_discount_reason'])) {
                throw new \InvalidArgumentException('Invoice discount reason is required.');
            }
            $totalAfterDiscount = round($total_before - $invoiceDiscount, 2);
            if ($totalAfterDiscount <= 0 || (float) $request['paid_amount'] > $totalAfterDiscount) {
                throw new Exception('Invoice total must be positive and paid amount cannot exceed it.');
            }

            $invoiceId = DB::table('sales_invoices')->insertGetId([
                'document_number' => app(DocumentNumberService::class)->next('sale', 'SAL'),
                'branch_id' => $request['branch_id'] ?? $this->resolveBranchId($user),
                'customer_id' => $request['customer_id'],
                'patient_id' => $request['patient_id'] ?? null,
                'prescription_id' => $request['prescription_id'] ?? null,
                'user_id' => $user->id,
                'discount' => $invoiceDiscount,
                'discount_type' => $invoiceDiscountType,
                'discount_value' => $invoiceDiscountValue,
                'discount_reason' => $request['invoice_discount_reason'] ?? null,
                'paid_amount' => $request['paid_amount'] ?? 0,
                'payment_method' => $request['payment_method'] ?? 'cash',
                'date' => $request['date'],
                'due_date' => $request['due_date'] ?? $request['date'],
                'total_price' => $totalAfterDiscount,
                'profit' => 0,
            ]);

            $saleLines = [];
            $profit = 0;

            $outstanding = $totalAfterDiscount - (float) $request['paid_amount'];
            if ($outstanding > 0) {
                $this->enforceCreditLimit((int) $request['customer_id'], $outstanding, $user, $request['credit_override_reason'] ?? null);
            }

            foreach ($normalizedItems as $item) {
                $required = (int) $item['_base_quantity'];
                $remaining = $required;

                $candidateBatches = $this->resolveSaleBatches($item);

                if ($candidateBatches->isEmpty()) {
                    throw new SalesInventoryException('No available stock for sale item.');
                }

                foreach ($candidateBatches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, (int) $batch->quantity);
                    if ($take <= 0) {
                        continue;
                    }

                    if ($batch->deleted_at !== null || ($batch->expiry_date !== null && $batch->expiry_date->toDateString() < now()->toDateString()) || $batch->quantity <= 0) {
                        continue;
                    }

                    $batch->applySale($take, $invoiceId, $user->id);
                    $remaining -= $take;

                    $itemDiscount = (float) $item['_discount'];
                    $lineDiscount = round($itemDiscount * ($take / $required), 2);
                    $transactionTake = $take / $item['_factor'];
                    $lineTotal = ((float) $item['price'] * $transactionTake) - $lineDiscount;
                    $batchCost = (float) $batch->purchase_price;
                    if (($lineTotal / $take) < $batchCost && ! $user->hasPermission('sales.sell_below_cost')) {
                        abort(403, 'Selling below cost requires permission.');
                    }
                    $profit += $lineTotal - ($take * $batchCost);

                    $saleLines[] = [
                        'sales_invoice_id' => $invoiceId,
                        'batch_id' => $batch->id,
                        'prescription_item_id' => $item['prescription_item_id'] ?? null,
                        'amount' => $take,
                        'price' => $item['price'] / $item['_factor'],
                        'transaction_unit_id' => $item['_unit_id'],
                        'transaction_quantity' => $transactionTake,
                        'base_quantity' => $take,
                        'original_unit_price' => $item['_default_price'],
                        'applied_unit_price' => $item['price'],
                        'discount_type' => $item['discount_type'] ?? 'fixed',
                        'discount_value' => $item['discount_value'] ?? $item['discount'] ?? 0,
                        'discount_reason' => $item['discount_reason'] ?? null,
                        'price_overridden' => $item['_override'],
                        'override_actor_id' => $item['_override'] ? $user->id : null,
                        'override_reason' => $item['override_reason'] ?? null,
                        'discount' => $lineDiscount,
                        'cost_price' => $batchCost,
                        'line_total' => $lineTotal,
                        'invoice_discount_share' => 0,
                        'final_total' => $lineTotal,
                        'profit' => $lineTotal - ($take * $batchCost),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                }

                if ($remaining > 0) {
                    throw new SalesInventoryException('Insufficient stock.');
                }
            }

            $lineSubtotal = (float) collect($saleLines)->sum('line_total');
            foreach ($saleLines as &$line) {
                $share = $lineSubtotal > 0 ? round($invoiceDiscount * ($line['line_total'] / $lineSubtotal), 2) : 0;
                $line['invoice_discount_share'] = $share;
                $line['final_total'] = $line['line_total'] - $share;
                $line['profit'] = $line['final_total'] - ($line['amount'] * $line['cost_price']);
            }
            unset($line);
            $profit = (float) collect($saleLines)->sum('profit');
            DB::table('prod_sales')->insert($saleLines);

            DB::table('sales_invoices')->where('id', $invoiceId)->update([
                'total_price' => $totalAfterDiscount,
                'profit' => $profit,
            ]);

            app(AccountingPostingService::class)->postSalesInvoice($invoiceId);
            if (($request['payment_method'] ?? 'cash') === 'cheque' && (float) ($request['paid_amount'] ?? 0) > 0) {
                app(ChequeService::class)->createLinked([
                    'cheque_number' => $request['cheque_number'], 'bank' => $request['cheque_bank'], 'amount' => (float) $request['paid_amount'],
                    'due_date' => $request['cheque_due_date'], 'direction' => 'incoming', 'sales_invoice_id' => $invoiceId, 'customer_id' => (int) $request['customer_id'],
                ]);
            }

            app(PrintIdentityService::class)->capture('sales_invoice', $invoiceId);

            return $this->document($invoiceId);
        });
    }

    protected function resolveSaleBatches($item)
    {
        if (! empty($item['batch_id'])) {
            return ProductBatch::query()->whereKey($item['batch_id'])->whereNull('deleted_at')->where('inventory_state', 'available')->where('quantity', '>', 0)->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))->lockForUpdate()->get();
        }

        if (! empty($item['product_id'])) {
            return ProductBatch::query()
                ->where('product_id', $item['product_id'])
                ->whereNull('deleted_at')
                ->where('inventory_state', 'available')
                ->where('quantity', '>', 0)
                ->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
        }

        if (! empty($item['barcode'])) {
            $productId = DB::table('products')->whereNull('deleted_at')->where('barcode', $item['barcode'])->value('id');
            if (! $productId) {
                return collect();
            }

            return ProductBatch::query()
                ->where('product_id', $productId)
                ->whereNull('deleted_at')
                ->where('inventory_state', 'available')
                ->where('quantity', '>', 0)
                ->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
        }

        return collect();
    }

    private function resolveBranchId($user): ?int
    {
        if (! empty($user->branch_id)) {
            return (int) $user->branch_id;
        }

        return DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');
    }

    private function enforceCreditLimit(int $customerId, float $newReceivable, $user, ?string $reason): void
    {
        $customer = DB::table('customers')->where('id', $customerId)->lockForUpdate()->first();
        if ($customer?->credit_limit === null || ! $customer->block_on_limit) {
            return;
        }
        $current = (float) app(AccountingReportService::class)->customerStatement($customerId)['balance'];
        if ($current + $newReceivable <= (float) $customer->credit_limit) {
            return;
        }
        if (! $user->hasPermission('customer.credit_override')) {
            abort(409, 'Customer credit limit exceeded.');
        }
        if (! $reason) {
            throw new \InvalidArgumentException('Credit override reason is required.');
        }
        $detail = json_encode(['reason' => $reason, 'previous_receivable' => $current, 'new_receivable' => $newReceivable]);
        DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'credit_override', 'action_type' => 'credit_override', 'table_name' => 'customers', 'record_id' => $customerId, 'new_data' => $detail, 'new_values' => $detail, 'created_at' => now()]);
    }

    public function show($id)
    {
        return $this->document((int) $id);
    }

    public function document(int $id): ?object
    {
        $invoice = DB::table('sales_invoices')->where('id', $id)->first();
        if ($invoice) {
            $invoice->status = $invoice->deleted_at ? 'cancelled' : 'posted';
            $invoice->cancelled = $invoice->deleted_at !== null;
            $invoice->customer = DB::table('customers')
                ->where('id', $invoice->customer_id)
                ->first(['id', 'name']);
            $returnQuantities = DB::table('invoice_returns')
                ->where('invoice_id', $id)
                ->groupBy('batch_id')
                ->selectRaw('batch_id, SUM(quantity) as returned_quantity')
                ->pluck('returned_quantity', 'batch_id');
            $lineCounts = DB::table('prod_sales')
                ->where('sales_invoice_id', $id)
                ->groupBy('batch_id')
                ->selectRaw('batch_id, COUNT(*) as line_count')
                ->pluck('line_count', 'batch_id');

            $invoice->items = DB::table('prod_sales as lines')
                ->join('product_batches as batches', 'batches.id', '=', 'lines.batch_id')
                ->join('products', 'products.id', '=', 'batches.product_id')
                ->where('lines.sales_invoice_id', $id)
                ->select([
                    'lines.*',
                    'products.id as product_identity_id',
                    'products.name as product_identity_name',
                    'products.barcode as product_identity_barcode',
                    'batches.id as batch_identity_id',
                    'batches.batch_number as batch_identity_number',
                    'batches.expiry_date as batch_identity_expiry_date',
                ])->get()->map(function ($line) use ($returnQuantities, $lineCounts) {
                    $line->product = (object) ['id' => $line->product_identity_id, 'name' => $line->product_identity_name, 'barcode' => $line->product_identity_barcode];
                    $line->batch = (object) ['id' => $line->batch_identity_id, 'batch_number' => $line->batch_identity_number, 'expiry_date' => $line->batch_identity_expiry_date];
                    $line->original_quantity = (int) $line->amount;
                    $line->return_linkage_ambiguous = (int) ($lineCounts[$line->batch_id] ?? 0) !== 1;
                    $line->returned_quantity = $line->return_linkage_ambiguous ? null : (int) ($returnQuantities[$line->batch_id] ?? 0);
                    $line->net_quantity = $line->return_linkage_ambiguous ? null : max(0, $line->original_quantity - $line->returned_quantity);
                    $line->available_return_quantity = $line->net_quantity;
                    unset($line->product_identity_id, $line->product_identity_name, $line->product_identity_barcode, $line->batch_identity_id, $line->batch_identity_number, $line->batch_identity_expiry_date);

                    return $line;
                });

            $legacyReturns = DB::table('invoice_returns')
                ->where('invoice_id', $id)->whereNull('sales_return_id')
                ->orderBy('id')
                ->get(['id', 'document_number', 'created_at', 'quantity', 'price', 'original_returnable_unit_amount', 'customer_credit_unit_amount', 'return_to_inventory', 'adjustment_reason', 'reason'])
                ->map(function ($return) {
                    $return->original_returnable_unit_amount = $return->original_returnable_unit_amount ?? $return->price;
                    $return->customer_credit_unit_amount = $return->customer_credit_unit_amount ?? $return->price;
                    $return->return_to_inventory = $return->return_to_inventory === null ? true : (bool) $return->return_to_inventory;
                    $return->gross_original_value = round((float) $return->quantity * (float) $return->original_returnable_unit_amount, 2);
                    $return->customer_credit_value = round((float) $return->quantity * (float) $return->customer_credit_unit_amount, 2);
                    $return->deduction_value = round($return->gross_original_value - $return->customer_credit_value, 2);
                    $return->value = $return->customer_credit_value;
                    return $return;
                });
            $groupedReturns = DB::table('sales_returns')->where('invoice_id',$id)->orderBy('id')->get()->map(function($return){
                $return->item_count = DB::table('invoice_returns')->where('sales_return_id',$return->id)->count();
                $return->quantity = (float) DB::table('invoice_returns')->where('sales_return_id',$return->id)->sum('quantity');
                $return->gross_original_value = (float) $return->gross_original_total;
                $return->customer_credit_value = (float) $return->customer_credit_total;
                $return->deduction_value = (float) $return->deduction_total;
                $return->value = $return->customer_credit_value;
                $return->return_to_inventory = null;
                return $return;
            });
            $invoice->linked_returns = $legacyReturns->concat($groupedReturns)->sortBy('created_at')->values();

            $originalTotal = round((float) $invoice->total_price, 2);
            $grossReturnTotal = round((float) $invoice->linked_returns->sum('gross_original_value'), 2);
            $customerCreditTotal = round((float) $invoice->linked_returns->sum('customer_credit_value'), 2);
            $returnDeductionTotal = round((float) $invoice->linked_returns->sum('deduction_value'), 2);
            $allocated = round((float) DB::table('settlement_allocations')
                ->where(['invoice_type' => 'sale', 'invoice_id' => $id])
                ->sum('amount'), 2);
            $settled = round((float) $invoice->paid_amount + $allocated, 2);
            $netTotal = round(max(0, $originalTotal - $customerCreditTotal), 2);
            $invoice->return_summary = (object) [
                'original_total' => $originalTotal,
                'return_total' => $customerCreditTotal,
                'gross_original_return_total' => $grossReturnTotal,
                'customer_credit_total' => $customerCreditTotal,
                'return_deduction_total' => $returnDeductionTotal,
                'net_total' => $netTotal,
                'paid_settled' => $settled,
                'net_outstanding' => round(max(0, $netTotal - $settled), 2),
                'excess_settlement' => round(max(0, $settled - $netTotal), 2),
            ];

            $hasAmbiguousLinkage = $invoice->items->contains(fn ($line) => $line->return_linkage_ambiguous);
            $hasReturns = $invoice->linked_returns->isNotEmpty() || $invoice->items->contains(fn ($line) => ($line->returned_quantity ?? 0) > 0);
            $fullyReturned = ! $hasAmbiguousLinkage && $invoice->items->isNotEmpty()
                && $invoice->items->every(fn ($line) => $line->net_quantity === 0);
            $invoice->return_status = $fullyReturned ? 'fully_returned' : ($hasReturns ? 'partially_returned' : 'no_returns');
        }

        return $invoice;
    }
}
